<?php

use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraIssueActions;
use App\Services\Jira\JiraPullRequestState;
use App\Services\Jira\JiraTransitionsCache;
use Illuminate\Support\Facades\Http;

/**
 * Build a Jira `getIssue` payload for the given key with sensible defaults.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function issuePayload(string $key, array $fields = []): array
{
    return [
        'id' => '10001',
        'key' => $key,
        'fields' => array_replace([
            'summary' => 'Refreshed summary',
            'status' => [
                'id' => '2',
                'name' => 'In Progress',
                'statusCategory' => ['name' => 'In Progress'],
            ],
            'issuetype' => ['name' => 'Task'],
            'priority' => ['name' => 'High'],
            'assignee' => null,
            'reporter' => ['displayName' => 'Reporter'],
            'created' => '2024-01-01T00:00:00.000+0000',
            'updated' => '2024-02-01T00:00:00.000+0000',
        ], $fields),
    ];
}

test('transition posts to jira and refreshes the row from the payload', function () {
    $user = User::factory()->withJiraConnection()->create();
    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-5',
        'issue_type' => 'Task',
        'status' => 'To Do',
        'status_id' => '1',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', [
        ['id' => '31', 'to_id' => '2', 'to_name' => 'In Progress'],
    ]);

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/issue/PROJ-5/transitions' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-5*' => Http::response(issuePayload('PROJ-5')),
    ]);

    $result = JiraIssueActions::forUser($user)->transition($issue, '2');

    expect($result->status_id)->toBe('2')
        ->and($result->status)->toBe('In Progress')
        ->and($issue->fresh()->status)->toBe('In Progress');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/rest/api/3/issue/PROJ-5/transitions')
            && $request->method() === 'POST'
            && $request['transition']['id'] === '31';
    });
});

test('transition to an unreachable status throws without calling jira', function () {
    $user = User::factory()->withJiraConnection()->create();
    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-6',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', [
        ['id' => '31', 'to_id' => '2', 'to_name' => 'In Progress'],
    ]);

    Http::fake();

    expect(fn () => JiraIssueActions::forUser($user)->transition($issue, '99'))
        ->toThrow(JiraApiException::class, 'Transition not available');

    Http::assertNothingSent();
});

test('a jira failure during transition leaves the row unchanged', function () {
    $user = User::factory()->withJiraConnection()->create();
    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-7',
        'issue_type' => 'Task',
        'status' => 'To Do',
        'status_id' => '1',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', [
        ['id' => '31', 'to_id' => '2', 'to_name' => 'In Progress'],
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-7/transitions' => Http::response(['errorMessages' => ['Nope']], 400),
    ]);

    expect(fn () => JiraIssueActions::forUser($user)->transition($issue, '2'))
        ->toThrow(JiraApiException::class);

    expect($issue->fresh()->status)->toBe('To Do')
        ->and($issue->fresh()->status_id)->toBe('1');
});

test('assign writes to jira and refreshes the row', function () {
    $user = User::factory()->withJiraConnection()->create();
    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-8',
        'assignee_account_id' => null,
        'assignee_name' => null,
    ]);

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/issue/PROJ-8/assignee' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-8*' => Http::response(issuePayload('PROJ-8', [
            'assignee' => ['accountId' => 'acc-9', 'displayName' => 'Assignee Nine'],
        ])),
    ]);

    $result = JiraIssueActions::forUser($user)->assign($issue, 'acc-9');

    expect($result->assignee_account_id)->toBe('acc-9')
        ->and($result->assignee_name)->toBe('Assignee Nine');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/rest/api/3/issue/PROJ-8/assignee')
            && $request->method() === 'PUT'
            && $request['accountId'] === 'acc-9';
    });
});

test('refreshing an issue updates its sprints and pull request summary from the custom fields', function () {
    $user = User::factory()->withJiraConnection()->create();
    $issue = JiraIssue::factory()->for($user)->inActiveSprint()->withPullRequest('OPEN', 1)->create([
        'jira_key' => 'PROJ-12',
        'sprints' => ['Sprint 1'],
    ]);

    Http::fake([
        '*/rest/api/3/field' => Http::response([
            ['id' => 'customfield_10020', 'schema' => ['custom' => 'com.pyxis.greenhopper.jira:gh-sprint']],
            ['id' => 'customfield_10000', 'schema' => ['custom' => 'com.atlassian.jira.plugins.jira-development-integration-plugin:devsummarycf']],
        ]),
        '*/rest/api/3/issue/PROJ-12/assignee' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-12*' => Http::response(issuePayload('PROJ-12', [
            'customfield_10020' => [['name' => 'Sprint 2', 'state' => 'active']],
            'customfield_10000' => json_encode([
                'cachedValue' => ['summary' => ['pullrequest' => ['overall' => ['count' => 2, 'state' => 'MERGED']]]],
            ]),
        ])),
    ]);

    JiraIssueActions::forUser($user)->assign($issue, 'acc-9');

    $issue->refresh();

    expect($issue->sprints)->toBe(['Sprint 2'])
        ->and($issue->in_active_sprint)->toBeTrue()
        ->and($issue->pr_state)->toBe(JiraPullRequestState::Merged)
        ->and($issue->pr_count)->toBe(2);

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && str_contains($request->url(), '/rest/api/3/issue/PROJ-12')
        && str_contains((string) $request['fields'], 'customfield_10020,customfield_10000'));
});

test('refreshing an issue keeps its sprints and pull request summary when the field lookup fails', function () {
    $user = User::factory()->withJiraConnection()->create();
    $issue = JiraIssue::factory()->for($user)->inActiveSprint()->withPullRequest('MERGED', 2)->create([
        'jira_key' => 'PROJ-13',
        'sprints' => ['Sprint 1'],
    ]);

    Http::fake([
        '*/rest/api/3/field' => Http::response(['errorMessages' => ['nope']], 403),
        '*/rest/api/3/issue/PROJ-13/assignee' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-13*' => Http::response(issuePayload('PROJ-13')),
    ]);

    JiraIssueActions::forUser($user)->assign($issue, 'acc-9');

    $issue->refresh();

    expect($issue->sprints)->toBe(['Sprint 1'])
        ->and($issue->in_active_sprint)->toBeTrue()
        ->and($issue->pr_state)->toBe(JiraPullRequestState::Merged)
        ->and($issue->pr_count)->toBe(2);
});

test('assigning an issue to me stamps it and clears its active snooze', function () {
    $this->freezeTime();

    $user = User::factory()->withJiraConnection()->create(['jira_account_id' => 'acc-me']);
    $issue = JiraIssue::factory()->for($user)->snoozed()->create([
        'jira_key' => 'PROJ-10',
        'assignee_account_id' => 'acc-other',
    ]);

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/issue/PROJ-10/assignee' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-10*' => Http::response(issuePayload('PROJ-10', [
            'assignee' => ['accountId' => 'acc-me', 'displayName' => 'Me'],
        ])),
    ]);

    JiraIssueActions::forUser($user)->assign($issue, 'acc-me');

    $issue->refresh();

    expect($issue->assigned_to_me_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($issue->snoozed_until)->toBeNull();
});

test('refreshing an issue already assigned to me does not stamp it and keeps its snooze despite newer jira activity', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_account_id' => 'acc-me']);

    $snoozedUntil = now()->addHour();

    $issue = JiraIssue::factory()->for($user)->snoozed($snoozedUntil)->create([
        'jira_key' => 'PROJ-11',
        'issue_type' => 'Task',
        'status_id' => '1',
        'assignee_account_id' => 'acc-me',
        'jira_updated_at' => '2024-01-01 00:00:00',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', [
        ['id' => '31', 'to_id' => '2', 'to_name' => 'In Progress'],
    ]);

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/issue/PROJ-11/transitions' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-11*' => Http::response(issuePayload('PROJ-11', [
            'assignee' => ['accountId' => 'acc-me', 'displayName' => 'Me'],
        ])),
    ]);

    JiraIssueActions::forUser($user)->transition($issue, '2');

    $issue->refresh();

    expect($issue->assigned_to_me_at)->toBeNull()
        ->and($issue->jira_updated_at->toDateTimeString())->toBe('2024-02-01 00:00:00')
        ->and($issue->snoozed_until->toDateTimeString())->toBe($snoozedUntil->toDateTimeString());
});

test('assigning an issue to someone else does not stamp it and keeps its snooze', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_account_id' => 'acc-me']);

    $snoozedUntil = now()->addHour();

    $issue = JiraIssue::factory()->for($user)->snoozed($snoozedUntil)->create([
        'jira_key' => 'PROJ-14',
        'assignee_account_id' => null,
    ]);

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/issue/PROJ-14/assignee' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-14*' => Http::response(issuePayload('PROJ-14', [
            'assignee' => ['accountId' => 'acc-9', 'displayName' => 'Assignee Nine'],
        ])),
    ]);

    JiraIssueActions::forUser($user)->assign($issue, 'acc-9');

    $issue->refresh();

    expect($issue->assigned_to_me_at)->toBeNull()
        ->and($issue->snoozed_until->toDateTimeString())->toBe($snoozedUntil->toDateTimeString());
});

test('addConcerning re-surfaces an existing task without contacting jira', function () {
    $user = User::factory()->withJiraConnection()->create();
    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-20',
        'concerning_since' => null,
        'dismissed_at' => now()->subDay(),
        'snoozed_until' => now()->addDay(),
    ]);

    Http::fake();

    $result = JiraIssueActions::forUser($user)->addConcerning(['PROJ-20']);

    expect($result['existing'])->toBe(['PROJ-20'])
        ->and($result['added'])->toBe([])
        ->and($result['failed'])->toBe([]);

    expect($issue->fresh()->concerning_since)->not->toBeNull()
        ->and($issue->fresh()->dismissed_at)->toBeNull()
        ->and($issue->fresh()->snoozed_until)->toBeNull();

    Http::assertNothingSent();
});

test('addConcerning fetches and creates an unknown task pinned to the list', function () {
    $user = User::factory()->withJiraConnection()->create();

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/issue/PROJ-21*' => Http::response(issuePayload('PROJ-21')),
    ]);

    $result = JiraIssueActions::forUser($user)->addConcerning(['PROJ-21']);

    expect($result['added'])->toBe(['PROJ-21']);

    $issue = JiraIssue::query()->where('user_id', $user->id)->where('jira_key', 'PROJ-21')->first();

    expect($issue)->not->toBeNull()
        ->and($issue->concerning_since)->not->toBeNull();
});

test('addConcerning stores the sprints and pull request summary of a newly fetched task', function () {
    $user = User::factory()->withJiraConnection()->create();

    Http::fake([
        '*/rest/api/3/field' => Http::response([
            ['id' => 'customfield_10020', 'schema' => ['custom' => 'com.pyxis.greenhopper.jira:gh-sprint']],
            ['id' => 'customfield_10000', 'schema' => ['custom' => 'com.atlassian.jira.plugins.jira-development-integration-plugin:devsummarycf']],
        ]),
        '*/rest/api/3/issue/PROJ-23*' => Http::response(issuePayload('PROJ-23', [
            'customfield_10020' => [['name' => 'Sprint 3', 'state' => 'active']],
            'customfield_10000' => json_encode([
                'cachedValue' => ['summary' => ['pullrequest' => ['overall' => ['count' => 1, 'state' => 'OPEN']]]],
            ]),
        ])),
    ]);

    JiraIssueActions::forUser($user)->addConcerning(['PROJ-23']);

    $issue = JiraIssue::query()->where('user_id', $user->id)->where('jira_key', 'PROJ-23')->firstOrFail();

    expect($issue->sprints)->toBe(['Sprint 3'])
        ->and($issue->pr_state)->toBe(JiraPullRequestState::Open)
        ->and($issue->pr_count)->toBe(1);
});

test('addConcerning records a per-key failure without aborting the batch', function () {
    $user = User::factory()->withJiraConnection()->create();

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/issue/PROJ-404*' => Http::response(['errorMessages' => ['Nope']], 404),
        '*/rest/api/3/issue/PROJ-22*' => Http::response(issuePayload('PROJ-22')),
    ]);

    $result = JiraIssueActions::forUser($user)->addConcerning(['PROJ-22', 'PROJ-404']);

    expect($result['added'])->toBe(['PROJ-22'])
        ->and($result['failed'])->toHaveKey('PROJ-404');

    expect(JiraIssue::query()->where('user_id', $user->id)->where('jira_key', 'PROJ-404')->exists())->toBeFalse();
});

test('assign failure throws and leaves the row unchanged', function () {
    $user = User::factory()->withJiraConnection()->create();
    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-9',
        'assignee_account_id' => 'acc-old',
        'assignee_name' => 'Old Assignee',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-9/assignee' => Http::response(['errorMessages' => ['Nope']], 400),
    ]);

    expect(fn () => JiraIssueActions::forUser($user)->assign($issue, 'acc-new'))
        ->toThrow(JiraApiException::class);

    expect($issue->fresh()->assignee_account_id)->toBe('acc-old');
});
