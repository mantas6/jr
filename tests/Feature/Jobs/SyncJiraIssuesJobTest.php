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

use function Pest\Laravel\freezeTime;
use function Pest\Laravel\travelTo;

// The sync job dispatches the mentions job at the end; fake the queue so the
// synchronous test connection does not run it inline (and exhaust HTTP fakes).
// Every sync also fetches the project's versions; release syncing is covered
// in SyncJiraReleasesTest, so these tests see a project without versions.
beforeEach(function () {
    Queue::fake();

    Http::fake(['*/rest/api/3/project/*/versions' => Http::response([])]);
});

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

test('sync replaces stale or incomplete PR data with the live summary', function (int $httpStatus, ?string $state, ?int $count, bool $stale) {
    $user = syncUser();
    $issue = jiraIssuePayload('1001', 'PROJ-1', 'First issue');
    $issue['fields']['customfield_10000'] = '{repository={count=7}, json='.json_encode([
        'cachedValue' => ['summary' => [
            'repository' => ['overall' => ['count' => 7]],
            'pullrequest' => ['overall' => ['count' => $stale ? 1 : 0, 'state' => 'MERGED']],
        ]],
        'isStale' => $stale,
    ], JSON_THROW_ON_ERROR).'}';

    Http::fake([
        '*/rest/api/3/field' => Http::response([
            ['id' => 'customfield_10000', 'schema' => ['custom' => 'com.atlassian.jira.plugins.jira-development-integration-plugin:devsummarycf']],
        ]),
        '*/rest/api/3/search/jql*' => Http::response(['issues' => [$issue], 'isLast' => true]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
        '*/rest/dev-status/1.0/issue/summary*' => Http::response([
            'summary' => ['pullrequest' => ['overall' => ['count' => 2, 'state' => $state]]],
        ], $httpStatus),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    $synced = JiraIssue::where('jira_id', '1001')->firstOrFail();

    expect($synced->pr_state?->value)->toBe($state)
        ->and($synced->pr_count)->toBe($count);
    expect($user->fresh()->jira_last_sync_error)->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/dev-status/1.0/issue/summary')
        && $request['issueId'] === '1001');
})->with([
    'live summary has missing PRs' => [200, 'MERGED', 2, false],
    'live summary unavailable still syncs issue' => [403, null, null, false],
    'stale merged summary now has an open PR' => [200, 'OPEN', 2, true],
    'stale merged summary with unavailable live data is not shown as merged' => [403, null, null, true],
]);

test('sync refreshes approvals for open and draft pull requests and keeps them when lookups fail', function () {
    $user = syncUser();
    $newlyApproved = JiraIssue::factory()->for($user)->withPullRequest('OPEN')->create(['jira_id' => '2001']);
    $noLongerApproved = JiraIssue::factory()->for($user)->withPullRequest('DRAFT', approved: true)->create(['jira_id' => '2002']);
    $unavailable = JiraIssue::factory()->for($user)->withPullRequest('OPEN', approved: true)->create(['jira_id' => '2003']);
    $summary = ['summary' => ['pullrequest' => ['byInstanceType' => ['bitbucket' => ['count' => 1]]]]];

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response(['issues' => [], 'isLast' => true]),
        '*/rest/dev-status/1.0/issue/summary?issueId=2001' => Http::response($summary),
        '*/rest/dev-status/1.0/issue/summary?issueId=2002' => Http::response($summary),
        '*/rest/dev-status/1.0/issue/summary?issueId=2003' => Http::response([], 403),
        '*/rest/dev-status/1.0/issue/detail?issueId=2001*' => Http::response(['detail' => [['pullRequests' => [
            ['id' => '1', 'status' => 'OPEN', 'reviewers' => [['name' => 'Alice', 'approved' => true]]],
        ]]]]),
        '*/rest/dev-status/1.0/issue/detail?issueId=2002*' => Http::response(['detail' => [['pullRequests' => [
            ['id' => '2', 'status' => 'DRAFT', 'reviewers' => [['name' => 'Alice', 'approved' => false]]],
        ]]]]),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    expect($newlyApproved->fresh()->pr_approved)->toBeTrue()
        ->and($noLongerApproved->fresh()->pr_approved)->toBeFalse()
        ->and($unavailable->fresh()->pr_approved)->toBeTrue();
});

test('an incremental sync refreshes the state and count of active pull requests on issues Jira did not report as updated', function () {
    travelTo(now()->startOfSecond());

    $user = syncUser([
        'jira_last_synced_at' => now()->subMinutes(10),
        'jira_last_full_synced_at' => now()->subHours(2),
    ]);
    $merged = JiraIssue::factory()->for($user)->withPullRequest('OPEN', 1)->create(['jira_id' => '2001']);
    $reopened = JiraIssue::factory()->for($user)->withPullRequest('DRAFT', 1)->create(['jira_id' => '2002']);
    $summary = ['summary' => ['pullrequest' => ['byInstanceType' => ['bitbucket' => ['count' => 2]]]]];

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response(['issues' => [], 'isLast' => true]),
        '*/rest/dev-status/1.0/issue/summary*' => Http::response($summary),
        '*/rest/dev-status/1.0/issue/detail?issueId=2001*' => Http::response(['detail' => [['pullRequests' => [
            ['id' => '1', 'status' => 'MERGED', 'reviewers' => [['name' => 'Alice', 'approved' => true]]],
            ['id' => '2', 'status' => 'DECLINED'],
        ]]]]),
        '*/rest/dev-status/1.0/issue/detail?issueId=2002*' => Http::response(['detail' => [['pullRequests' => [
            ['id' => '3', 'status' => 'OPEN', 'reviewers' => [['name' => 'Alice', 'approved' => true]]],
            ['id' => '4', 'status' => 'MERGED'],
        ]]]]),
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    assertSyncJql('project = "PROJ" AND updated >= -15m ORDER BY updated DESC');

    $merged->refresh();
    $reopened->refresh();

    expect($merged->pr_state)->toBe(JiraPullRequestState::Merged)
        ->and($merged->pr_count)->toBe(2)
        ->and($merged->pr_approved)->toBeFalse()
        ->and($reopened->pr_state)->toBe(JiraPullRequestState::Open)
        ->and($reopened->pr_count)->toBe(2)
        ->and($reopened->pr_approved)->toBeTrue();
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
    freezeTime();

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

/**
 * Fake a sync whose single search result is issue 1001 assigned to the given
 * account (or nobody).
 */
function fakeIssueSyncWithAssignee(?string $accountId): void
{
    $payload = jiraIssuePayload('1001', 'PROJ-1', 'Reassigned issue');
    $payload['fields']['assignee'] = $accountId === null ? null : ['accountId' => $accountId, 'displayName' => 'Someone Else'];

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/search/jql*' => Http::response(['issues' => [$payload], 'isLast' => true]),
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
    ]);
}

test('an unstarred issue reassigned away from me is dismissed into the passed list', function (?string $newAssignee) {
    freezeTime();

    $user = syncUser(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->concerning()->create([
        'jira_id' => '1001',
        'jira_key' => 'PROJ-1',
        'assignee_account_id' => 'acc-me',
    ]);

    fakeIssueSyncWithAssignee($newAssignee);

    (new SyncJiraIssuesJob($user))->handle();

    $issue->refresh();

    expect($issue->assignee_account_id)->toBe($newAssignee)
        ->and($issue->dismissed_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($issue->is_important)->toBeFalse()
        ->and($issue->concerning_since)->toBeNull()
        ->and(JiraIssue::query()->passed($user)->pluck('id')->all())->toBe([$issue->id]);
})->with([
    'to someone else' => ['acc-other'],
    'to nobody' => [null],
]);

test('a starred issue reassigned away from me is left alone', function () {
    $user = syncUser(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->important()->create([
        'jira_id' => '1001',
        'jira_key' => 'PROJ-1',
        'assignee_account_id' => 'acc-me',
    ]);

    fakeIssueSyncWithAssignee('acc-other');

    (new SyncJiraIssuesJob($user))->handle();

    $issue->refresh();

    expect($issue->assignee_account_id)->toBe('acc-other')
        ->and($issue->dismissed_at)->toBeNull()
        ->and($issue->is_important)->toBeTrue()
        ->and($issue->concerning_since)->not->toBeNull();
});

test('an issue that stays assigned to someone else is not dismissed', function () {
    $user = syncUser(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->concerning()->create([
        'jira_id' => '1001',
        'jira_key' => 'PROJ-1',
        'assignee_account_id' => 'acc-someone',
    ]);

    fakeIssueSyncWithAssignee('acc-other');

    (new SyncJiraIssuesJob($user))->handle();

    expect($issue->fresh()->dismissed_at)->toBeNull()
        ->and($issue->fresh()->concerning_since)->not->toBeNull();
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

test('a non-forced run is dropped behind an in-flight sync while a forced run waits for it', function (bool $force, ?int $releaseAfter) {
    $user = syncUser();

    $middleware = collect((new SyncJiraIssuesJob($user, $force))->middleware())
        ->whereInstanceOf(WithoutOverlapping::class)
        ->first();

    expect($middleware)->not->toBeNull()
        ->and($middleware->key)->toBe((string) $user->id)
        ->and($middleware->releaseAfter)->toBe($releaseAfter)
        ->and($middleware->expiresAfter)->toBe(330);
})->with([
    'non-forced' => [false, null],
    'forced' => [true, 15],
]);

test('a forced run keeps retrying until an abandoned overlap lock has expired', function () {
    $job = new SyncJiraIssuesJob(syncUser(), force: true);

    $overlapLockSeconds = collect($job->middleware())
        ->whereInstanceOf(WithoutOverlapping::class)
        ->first()
        ->expiresAfter;

    expect($job->retryUntil()->isAfter(now()->addSeconds($overlapLockSeconds)))->toBeTrue();
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
    freezeTime();

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
    travelTo(now()->startOfSecond());

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
    freezeTime();

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
