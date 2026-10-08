<?php

use App\Jobs\SyncJiraIssuesJob;
use App\Jobs\SyncJiraMentionsJob;
use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraPullRequestState;
use App\Services\Jira\JiraTransitionsCache;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// The sync job dispatches the mentions job at the end; fake the queue so the
// synchronous test connection does not run it inline (and exhaust HTTP fakes).
beforeEach(fn () => Queue::fake());

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

    $estimatedIssue = jiraIssuePayload('1001', 'PROJ-1', 'First issue');
    $estimatedIssue['fields']['timeoriginalestimate'] = 12600;

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::sequence()
            ->push([
                'issues' => [
                    $estimatedIssue,
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
        ->and($issue->original_estimate_seconds)->toBe(12600)
        ->and($issue->jira_url)->toBe('https://example.atlassian.net/browse/PROJ-1')
        ->and(JiraIssue::where('jira_id', '1002')->firstOrFail()->original_estimate_seconds)->toBeNull();

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

test('it syncs the pull request summary using the auto-detected development field', function () {
    $user = syncUser();

    $issue = jiraIssuePayload('1001', 'PROJ-1', 'First issue');
    $issue['fields']['customfield_10000'] = json_encode([
        'cachedValue' => ['summary' => ['pullrequest' => ['overall' => ['count' => 2, 'state' => 'MERGED']]]],
    ]);

    Http::fake([
        '*/rest/api/3/field' => Http::response([
            ['id' => 'customfield_10000', 'schema' => ['custom' => 'com.atlassian.jira.plugins.jira-development-integration-plugin:devsummarycf']],
        ]),
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [$issue],
            'isLast' => true,
        ]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    $synced = JiraIssue::where('jira_id', '1001')->firstOrFail();

    expect($synced->pr_state)->toBe(JiraPullRequestState::Merged)
        ->and($synced->pr_count)->toBe(2);

    Http::assertSent(fn (Request $request) => isset($request['fields']) && str_contains((string) $request['fields'], 'customfield_10000'));
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

/**
 * Fake a sync whose single search result is issue 1001 assigned to `acc-1`.
 */
function fakeAssignedIssueSync(): void
{
    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [jiraIssuePayload('1001', 'PROJ-1', 'Assigned issue')],
            'isLast' => true,
        ]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
    ]);
}

test('a newly assigned issue is stamped and its active snooze is cleared', function () {
    $this->freezeTime();

    $user = syncUser(['jira_account_id' => 'acc-1']);

    $issue = JiraIssue::factory()->for($user)->snoozed()->create([
        'jira_id' => '1001',
        'jira_key' => 'PROJ-1',
        'assignee_account_id' => 'acc-other',
    ]);

    fakeAssignedIssueSync();

    (new SyncJiraIssuesJob($user))->handle();

    $issue->refresh();

    expect($issue->assigned_to_me_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($issue->snoozed_until)->toBeNull();
});

test('an issue that was already assigned to me is not stamped and keeps its snooze despite newer jira activity', function () {
    $user = syncUser(['jira_account_id' => 'acc-1']);

    $snoozedUntil = now()->addHour();

    $issue = JiraIssue::factory()->for($user)->snoozed($snoozedUntil)->create([
        'jira_id' => '1001',
        'jira_key' => 'PROJ-1',
        'assignee_account_id' => 'acc-1',
        'jira_updated_at' => '2024-01-01 10:00:00',
    ]);

    fakeAssignedIssueSync();

    (new SyncJiraIssuesJob($user))->handle();

    $issue->refresh();

    expect($issue->assigned_to_me_at)->toBeNull()
        ->and($issue->jira_updated_at->toDateTimeString())->toBe('2024-02-01 10:00:00')
        ->and($issue->snoozed_until->toDateTimeString())->toBe($snoozedUntil->toDateTimeString());
});

test('an issue reassigned to someone else is not stamped and keeps its snooze', function () {
    $user = syncUser(['jira_account_id' => 'acc-me']);

    $snoozedUntil = now()->addHour();

    $issue = JiraIssue::factory()->for($user)->snoozed($snoozedUntil)->create([
        'jira_id' => '1001',
        'jira_key' => 'PROJ-1',
        'assignee_account_id' => null,
    ]);

    fakeAssignedIssueSync();

    (new SyncJiraIssuesJob($user))->handle();

    $issue->refresh();

    expect($issue->assignee_account_id)->toBe('acc-1')
        ->and($issue->assigned_to_me_at)->toBeNull()
        ->and($issue->snoozed_until->toDateTimeString())->toBe($snoozedUntil->toDateTimeString());
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

test('a 401 from search fails the job and failed() records the error message', function () {
    $user = syncUser();

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response(['errorMessages' => ['nope']], 401),
    ]);

    $job = (new SyncJiraIssuesJob($user))->withFakeQueueInteractions();

    $job->handle();

    $job->assertFailedWith(JiraApiException::class);

    $exception = $job->job->failedWith;
    $job->failed($exception);

    expect($exception->getMessage())
        ->toBe('Jira rejected the credentials (401). Check your email and API token.')
        ->and($user->fresh()->jira_last_sync_error)
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

test('the job releases behind an in-flight sync per user', function () {
    $user = syncUser();

    $middleware = collect((new SyncJiraIssuesJob($user))->middleware())
        ->firstWhere(fn ($m): bool => $m instanceof WithoutOverlapping);

    expect($middleware)->not->toBeNull()
        ->and($middleware->key)->toBe((string) $user->id)
        ->and($middleware->releaseAfter)->toBe(15)
        ->and($middleware->expiresAfter)->toBe(600);
});

test('a successful sync dispatches the mentions scan job', function () {
    $user = syncUser();

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [jiraIssuePayload('1001', 'PROJ-1', 'First issue')],
            'isLast' => true,
        ]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    Queue::assertPushed(
        SyncJiraMentionsJob::class,
        fn (SyncJiraMentionsJob $job): bool => $job->user->is($user),
    );
});

/**
 * Fake a minimal single-issue search response for JQL inspection tests.
 */
function fakeSingleIssueSync(): void
{
    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [jiraIssuePayload('1001', 'PROJ-1', 'First issue')],
            'isLast' => true,
        ]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
    ]);
}

/**
 * Assert the JQL sent to the search endpoint matches the expectation.
 */
function assertSyncJql(string $expected): void
{
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/search/jql')
        && $request['jql'] === $expected);
}

test('a first run with no prior full sync performs a full project sync', function () {
    $user = syncUser();

    fakeSingleIssueSync();

    (new SyncJiraIssuesJob($user))->handle();

    assertSyncJql('project = "PROJ" ORDER BY updated DESC');

    expect($user->fresh()->jira_last_full_synced_at)->not->toBeNull();
});

test('a stale full sync older than 24h promotes to a full sync', function () {
    $this->freezeTime();

    $user = syncUser([
        'jira_last_synced_at' => now()->subMinutes(10),
        'jira_last_full_synced_at' => now()->subHours(25),
    ]);

    fakeSingleIssueSync();

    (new SyncJiraIssuesJob($user))->handle();

    assertSyncJql('project = "PROJ" ORDER BY updated DESC');

    expect($user->fresh()->jira_last_full_synced_at->toDateTimeString())
        ->toBe(now()->toDateTimeString());
});

test('a recent full sync runs incrementally with a relative updated window', function () {
    $this->travelTo(now()->startOfSecond());

    $fullSyncedAt = now()->subHours(2);

    $user = syncUser([
        'jira_last_synced_at' => now()->subMinutes(10),
        'jira_last_full_synced_at' => $fullSyncedAt,
    ]);

    fakeSingleIssueSync();

    (new SyncJiraIssuesJob($user))->handle();

    assertSyncJql('project = "PROJ" AND updated >= -15m ORDER BY updated DESC');

    $user->refresh();

    expect($user->jira_last_full_synced_at->toDateTimeString())
        ->toBe($fullSyncedAt->toDateTimeString())
        ->and($user->jira_last_synced_at->toDateTimeString())
        ->toBe(now()->toDateTimeString());
});

test('a forced run performs a full sync even when a recent full sync exists', function () {
    $this->freezeTime();

    $user = syncUser([
        'jira_last_synced_at' => now()->subMinutes(10),
        'jira_last_full_synced_at' => now()->subHours(2),
    ]);

    fakeSingleIssueSync();

    (new SyncJiraIssuesJob($user, force: true))->handle();

    assertSyncJql('project = "PROJ" ORDER BY updated DESC');

    expect($user->fresh()->jira_last_full_synced_at->toDateTimeString())
        ->toBe(now()->toDateTimeString());
});

test('a forced run uses a distinct unique id so it is not deduped', function () {
    $user = syncUser();

    expect((new SyncJiraIssuesJob($user))->uniqueId())->toBe((string) $user->id)
        ->and((new SyncJiraIssuesJob($user, force: true))->uniqueId())->toBe($user->id.':force');
});
