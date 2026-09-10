<?php

use App\Jobs\SyncJiraIssuesJob;
use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraTransitionsCache;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Build a connected user for the sync job.
 */
function syncUser(array $attributes = []): User
{
    return User::factory()->withJiraConnection()->create(array_merge([
        'jira_site_url' => 'https://example.atlassian.net',
        'jira_email' => 'me@example.com',
        'jira_api_token' => 'secret-token',
        'jira_project_key' => 'PROJ',
    ], $attributes));
}

/**
 * Build a Jira issue payload for search responses.
 */
function jiraIssuePayload(string $id, string $key, string $summary, string $type = 'Task', string $statusId = '1'): array
{
    return [
        'id' => $id,
        'key' => $key,
        'fields' => [
            'summary' => $summary,
            'status' => [
                'name' => 'To Do',
                'id' => $statusId,
                'statusCategory' => ['name' => 'To Do'],
            ],
            'issuetype' => ['name' => $type],
            'priority' => ['name' => 'Medium'],
            'assignee' => ['accountId' => 'acc-1', 'displayName' => 'Jane Doe'],
            'reporter' => ['displayName' => 'John Reporter'],
            'created' => '2024-01-01T10:00:00.000+0000',
            'updated' => '2024-02-01T10:00:00.000+0000',
        ],
    ];
}

test('it pages through search results and upserts every issue', function () {
    $user = syncUser();

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::sequence()
            ->push([
                'issues' => [
                    jiraIssuePayload('1001', 'PROJ-1', 'First issue'),
                    jiraIssuePayload('1002', 'PROJ-2', 'Second issue'),
                ],
                'nextPageToken' => 'page-2',
                'isLast' => false,
            ])
            ->push([
                'issues' => [
                    jiraIssuePayload('1003', 'PROJ-3', 'Third issue'),
                ],
                'isLast' => true,
            ]),
        '*/rest/api/3/issue/*/transitions' => Http::response([
            'transitions' => [
                ['id' => '11', 'name' => 'Start', 'to' => ['id' => '2', 'name' => 'In Progress']],
            ],
        ]),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    expect(JiraIssue::where('user_id', $user->id)->count())->toBe(3);

    $issue = JiraIssue::where('jira_id', '1001')->firstOrFail();

    expect($issue->jira_key)->toBe('PROJ-1')
        ->and($issue->summary)->toBe('First issue')
        ->and($issue->status)->toBe('To Do')
        ->and($issue->status_id)->toBe('1')
        ->and($issue->status_category)->toBe('To Do')
        ->and($issue->issue_type)->toBe('Task')
        ->and($issue->priority)->toBe('Medium')
        ->and($issue->assignee_account_id)->toBe('acc-1')
        ->and($issue->assignee_name)->toBe('Jane Doe')
        ->and($issue->reporter_name)->toBe('John Reporter')
        ->and($issue->jira_url)->toBe('https://example.atlassian.net/browse/PROJ-1');

    Http::assertSent(fn (Request $request) => isset($request['nextPageToken']) && $request['nextPageToken'] === 'page-2');
});

test('it syncs sprint names using the auto-detected sprint field', function () {
    $user = syncUser();

    $issue = jiraIssuePayload('1001', 'PROJ-1', 'First issue');
    $issue['fields']['customfield_10020'] = [
        ['name' => 'Sprint 1', 'state' => 'closed'],
        ['name' => 'Sprint 2', 'state' => 'active'],
    ];

    Http::fake([
        '*/rest/api/3/field' => Http::response([
            ['id' => 'customfield_10020', 'schema' => ['custom' => 'com.pyxis.greenhopper.jira:gh-sprint']],
        ]),
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [$issue],
            'isLast' => true,
        ]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    expect(JiraIssue::where('jira_id', '1001')->firstOrFail()->sprints)
        ->toBe(['Sprint 1', 'Sprint 2']);

    Http::assertSent(fn (Request $request) => isset($request['fields']) && str_contains((string) $request['fields'], 'customfield_10020'));
});

test('a sync without a sprint field stores null sprints', function () {
    $user = syncUser();

    Http::fake([
        '*/rest/api/3/field' => Http::response([
            ['id' => 'summary', 'schema' => ['type' => 'string']],
        ]),
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [jiraIssuePayload('1001', 'PROJ-1', 'First issue')],
            'isLast' => true,
        ]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    expect(JiraIssue::where('jira_id', '1001')->firstOrFail()->sprints)->toBeNull();
});

test('re-running the sync updates existing rows without duplicating them', function () {
    $user = syncUser();

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::sequence()
            ->push([
                'issues' => [jiraIssuePayload('1001', 'PROJ-1', 'Original summary')],
                'isLast' => true,
            ])
            ->push([
                'issues' => [jiraIssuePayload('1001', 'PROJ-1', 'Updated summary')],
                'isLast' => true,
            ]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    expect(JiraIssue::where('user_id', $user->id)->count())->toBe(1);

    (new SyncJiraIssuesJob($user))->handle();

    expect(JiraIssue::where('user_id', $user->id)->count())->toBe(1)
        ->and(JiraIssue::where('jira_id', '1001')->firstOrFail()->summary)->toBe('Updated summary');
});

test('a successful sync stamps the synced time and clears any previous error', function () {
    $user = syncUser(['jira_last_sync_error' => 'previous failure']);

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [jiraIssuePayload('1001', 'PROJ-1', 'First issue')],
            'isLast' => true,
        ]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    $user->refresh();

    expect($user->jira_last_sync_error)->toBeNull()
        ->and($user->jira_last_synced_at)->not->toBeNull();
});

test('it warms the transitions cache for each synced issue type and status', function () {
    $user = syncUser();

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [jiraIssuePayload('1001', 'PROJ-1', 'First issue', 'Task', '1')],
            'isLast' => true,
        ]),
        '*/rest/api/3/issue/*/transitions' => Http::response([
            'transitions' => [
                ['id' => '11', 'name' => 'Start', 'to' => ['id' => '2', 'name' => 'In Progress']],
                ['id' => '21', 'name' => 'Finish', 'to' => ['id' => '3', 'name' => 'Done']],
            ],
        ]),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    $key = JiraTransitionsCache::key($user, 'Task', '1');

    expect(Cache::has($key))->toBeTrue()
        ->and(Cache::get($key))->toBe([
            ['id' => '11', 'to_id' => '2', 'to_name' => 'In Progress'],
            ['id' => '21', 'to_id' => '3', 'to_name' => 'Done'],
        ]);
});

test('a failing transitions call does not abort the sync', function () {
    $user = syncUser();

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [jiraIssuePayload('1001', 'PROJ-1', 'First issue')],
            'isLast' => true,
        ]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['errorMessages' => ['nope']], 403),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    expect(JiraIssue::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->jira_last_synced_at)->not->toBeNull();
});

test('a 401 from search throws and failed() records the error message', function () {
    $user = syncUser();

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response(['errorMessages' => ['nope']], 401),
    ]);

    $job = new SyncJiraIssuesJob($user);

    try {
        $job->handle();
        $this->fail('Expected JiraApiException was not thrown.');
    } catch (JiraApiException $exception) {
        $job->failed($exception);
    }

    expect($user->fresh()->jira_last_sync_error)
        ->toBe('Jira rejected the credentials (401). Check your email and API token.');
});

test('a user without a Jira connection triggers no HTTP calls', function () {
    Http::fake();

    $user = User::factory()->create();

    (new SyncJiraIssuesJob($user))->handle();

    Http::assertNothingSent();
});

test('the job is unique per user so a duplicate dispatch is ignored', function () {
    Queue::fake();

    $user = syncUser();

    SyncJiraIssuesJob::dispatch($user);
    SyncJiraIssuesJob::dispatch($user);

    Queue::assertPushed(SyncJiraIssuesJob::class, 1);
});
