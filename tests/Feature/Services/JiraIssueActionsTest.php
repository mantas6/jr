<?php

use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraIssueActions;
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
