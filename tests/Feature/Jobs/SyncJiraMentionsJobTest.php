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
