<?php

use App\Jobs\SyncJiraMentionsJob;
use App\Models\JiraIssue;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Build a connected user with a known Jira account id.
 */
function mentionsUser(array $attributes = []): User
{
    return User::factory()->withJiraConnection()->create(array_merge([
        'jira_account_id' => 'acc-me',
        'jira_project_key' => 'PROJ',
    ], $attributes));
}

/**
 * Build a search issue payload with an optional description mention.
 *
 * @return array<string, mixed>
 */
function mentionIssuePayload(string $id, string $key, ?string $mentionAccountId = null): array
{
    $description = $mentionAccountId === null
        ? ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'plain']]]]]
        : ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => $mentionAccountId]]]]]];

    return [
        'id' => $id,
        'key' => $key,
        'fields' => [
            'description' => $description,
            'comment' => ['comments' => []],
        ],
    ];
}

test('it flags issues that mention the user and clears those that do not', function () {
    $user = mentionsUser();

    $mentioned = JiraIssue::factory()->for($user)->create(['jira_id' => '1001']);
    $notMentioned = JiraIssue::factory()->for($user)->mentionsMe()->create(['jira_id' => '1002']);

    Http::fake([
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [
                mentionIssuePayload('1001', 'PROJ-1', 'acc-me'),
                mentionIssuePayload('1002', 'PROJ-2', null),
            ],
            'isLast' => true,
        ]),
    ]);

    (new SyncJiraMentionsJob($user))->handle();

    expect($mentioned->fresh()->mentions_me)->toBeTrue()
        ->and($mentioned->fresh()->mentions_scanned_at)->not->toBeNull()
        ->and($notMentioned->fresh()->mentions_me)->toBeFalse();

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['jql'], 'updated >= -14d')
        && str_contains((string) $request['fields'], 'description')
        && str_contains((string) $request['fields'], 'comment'));
});

test('it detects a mention inside a comment body', function () {
    $user = mentionsUser();

    $issue = JiraIssue::factory()->for($user)->create(['jira_id' => '2001']);

    $payload = mentionIssuePayload('2001', 'PROJ-9', null);
    $payload['fields']['comment']['comments'] = [
        ['body' => ['type' => 'doc', 'content' => [['type' => 'mention', 'attrs' => ['id' => 'acc-me']]]]],
    ];

    Http::fake([
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [$payload],
            'isLast' => true,
        ]),
    ]);

    (new SyncJiraMentionsJob($user))->handle();

    expect($issue->fresh()->mentions_me)->toBeTrue();
});

test('a user without a Jira account id triggers no HTTP calls', function () {
    Http::fake();

    $user = User::factory()->withJiraConnection()->create(['jira_account_id' => null]);

    (new SyncJiraMentionsJob($user))->handle();

    Http::assertNothingSent();
});

test('a user without a Jira connection triggers no HTTP calls', function () {
    Http::fake();

    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    (new SyncJiraMentionsJob($user))->handle();

    Http::assertNothingSent();
});

/**
 * Build a Jira comment whose body mentions the given account.
 *
 * @return array<string, mixed>
 */
function mentionComment(string $authorId, string $created, ?string $updated = null, ?string $updateAuthorId = null, string $mentionedId = 'acc-me'): array
{
    return [
        'author' => ['accountId' => $authorId],
        'updateAuthor' => ['accountId' => $updateAuthorId ?? $authorId],
        'created' => $created,
        'updated' => $updated ?? $created,
        'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => $mentionedId]]]]]],
    ];
}

/**
 * Fake a mentions scan returning a single issue with the given comments.
 *
 * @param  list<array<string, mixed>>  $comments
 */
function fakeMentionScan(string $id, string $key, array $comments, ?string $descriptionMentionId = null): void
{
    $payload = mentionIssuePayload($id, $key, $descriptionMentionId);
    $payload['fields']['comment'] = ['comments' => $comments, 'total' => count($comments)];

    Http::fake([
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [$payload],
            'isLast' => true,
        ]),
    ]);
}

test('it stores the newest mention by someone else and ignores my own comments', function () {
    $user = mentionsUser();

    $issue = JiraIssue::factory()->for($user)->create(['jira_id' => '3001']);

    fakeMentionScan('3001', 'PROJ-30', [
        mentionComment('acc-other', '2024-03-01T10:00:00.000+0000', '2024-03-05T12:30:00.000+0200'),
        mentionComment('acc-other', '2024-03-02T10:00:00.000+0000'),
        mentionComment('acc-me', '2024-03-09T10:00:00.000+0000'),
        mentionComment('acc-other', '2024-03-08T10:00:00.000+0000', mentionedId: 'acc-someone'),
    ]);

    (new SyncJiraMentionsJob($user))->handle();

    $issue->refresh();

    expect($issue->mentions_me)->toBeTrue()
        ->and($issue->last_mentioned_at->toDateTimeString())->toBe('2024-03-05 10:30:00');
});

test('my own edit of someone elses comment counts from when it was created', function () {
    $user = mentionsUser();

    $issue = JiraIssue::factory()->for($user)->create(['jira_id' => '3002']);

    fakeMentionScan('3002', 'PROJ-31', [
        mentionComment('acc-other', '2024-03-01T10:00:00.000+0000', '2024-03-07T10:00:00.000+0000', updateAuthorId: 'acc-me'),
    ]);

    (new SyncJiraMentionsJob($user))->handle();

    expect($issue->fresh()->last_mentioned_at->toDateTimeString())->toBe('2024-03-01 10:00:00');
});

test('a description only mention flags the issue without a mention time', function () {
    $user = mentionsUser();

    $issue = JiraIssue::factory()->for($user)->create(['jira_id' => '3003']);

    fakeMentionScan('3003', 'PROJ-32', [], 'acc-me');

    (new SyncJiraMentionsJob($user))->handle();

    $issue->refresh();

    expect($issue->mentions_me)->toBeTrue()
        ->and($issue->last_mentioned_at)->toBeNull();
});

test('the mention time never moves backward and an older mention keeps the snooze', function () {
    $this->freezeTime();

    $user = mentionsUser();

    $snoozedUntil = now()->addHour();

    $issue = JiraIssue::factory()->for($user)->snoozed($snoozedUntil)->mentionedAt(now()->subDay())->create(['jira_id' => '3004']);

    fakeMentionScan('3004', 'PROJ-33', [
        mentionComment('acc-other', '2024-03-01T10:00:00.000+0000'),
    ]);

    (new SyncJiraMentionsJob($user))->handle();

    $issue->refresh();

    expect($issue->last_mentioned_at->toDateTimeString())->toBe(now()->subDay()->toDateTimeString())
        ->and($issue->snoozed_until->toDateTimeString())->toBe($snoozedUntil->toDateTimeString());
});

test('a newer mention clears an active snooze', function () {
    $user = mentionsUser();

    $issue = JiraIssue::factory()->for($user)->snoozed(now()->addDays(30))->mentionedAt(now()->subWeek())->create(['jira_id' => '3005']);

    $mentionedAt = now()->subMinute()->startOfSecond();

    fakeMentionScan('3005', 'PROJ-34', [
        mentionComment('acc-other', $mentionedAt->toIso8601String()),
    ]);

    (new SyncJiraMentionsJob($user))->handle();

    $issue->refresh();

    expect($issue->last_mentioned_at->toDateTimeString())->toBe($mentionedAt->toDateTimeString())
        ->and($issue->snoozed_until)->toBeNull();
});

test('it fetches the full comment list when the embedded comments are truncated', function () {
    $user = mentionsUser();

    $issue = JiraIssue::factory()->for($user)->create(['jira_id' => '3006']);

    $payload = mentionIssuePayload('3006', 'PROJ-35', null);
    $payload['fields']['comment'] = [
        'comments' => [mentionComment('acc-other', '2024-03-01T10:00:00.000+0000', mentionedId: 'acc-someone')],
        'total' => 3,
    ];

    Http::fake([
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [$payload],
            'isLast' => true,
        ]),
        '*/rest/api/3/issue/PROJ-35/comment*' => Http::sequence()
            ->push([
                'startAt' => 0,
                'total' => 3,
                'comments' => [
                    mentionComment('acc-other', '2024-03-01T10:00:00.000+0000', mentionedId: 'acc-someone'),
                    mentionComment('acc-other', '2024-03-02T10:00:00.000+0000'),
                ],
            ])
            ->push([
                'startAt' => 2,
                'total' => 3,
                'comments' => [mentionComment('acc-other', '2024-03-03T10:00:00.000+0000')],
            ]),
    ]);

    (new SyncJiraMentionsJob($user))->handle();

    $issue->refresh();

    expect($issue->mentions_me)->toBeTrue()
        ->and($issue->last_mentioned_at->toDateTimeString())->toBe('2024-03-03 10:00:00');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/rest/api/3/issue/PROJ-35/comment')
        && (int) $request['startAt'] === 2);
});
