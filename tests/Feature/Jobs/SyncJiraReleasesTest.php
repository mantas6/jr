<?php

use App\Jobs\SyncJiraIssuesJob;
use App\Models\JiraIssue;
use App\Models\JiraRelease;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// The sync job dispatches the mentions job at the end; fake the queue so the
// synchronous test connection does not run it inline.
beforeEach(fn () => Queue::fake());

/**
 * Build a connected user for the sync job.
 *
 * @param  array<string, mixed>  $attributes
 */
function releaseSyncUser(array $attributes = []): User
{
    return User::factory()->withJiraConnection()->create(array_merge([
        'jira_site_url' => 'https://example.atlassian.net/',
        'jira_project_key' => 'PROJ',
    ], $attributes));
}

/**
 * Build a Jira project version payload.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function jiraVersionPayload(string $id, string $name, array $attributes = []): array
{
    return array_merge([
        'id' => $id,
        'name' => $name,
        'archived' => false,
        'released' => false,
        'projectId' => 10000,
    ], $attributes);
}

/**
 * Build a Jira issue search payload with the given fix version ids.
 *
 * @param  list<string>  $fixVersionIds
 * @return array<string, mixed>
 */
function jiraReleaseIssuePayload(string $id, string $key, array $fixVersionIds = []): array
{
    return [
        'id' => $id,
        'key' => $key,
        'fields' => [
            'summary' => 'Issue '.$key,
            'status' => ['name' => 'To Do', 'id' => '1', 'statusCategory' => ['name' => 'To Do']],
            'issuetype' => ['name' => 'Task'],
            'created' => '2024-01-01T10:00:00.000+0000',
            'updated' => '2024-02-01T10:00:00.000+0000',
            'fixVersions' => array_map(fn (string $versionId): array => ['id' => $versionId, 'name' => 'v'.$versionId], $fixVersionIds),
        ],
    ];
}

/**
 * Fake the Jira endpoints called by one or more consecutive syncs, each run
 * given as `[versions, issues]` (versions null to fail that run's lookup).
 *
 * @param  list<array{0: list<array<string, mixed>>|null, 1: list<array<string, mixed>>}>  $runs
 */
function fakeReleaseSyncs(array $runs): void
{
    $versions = Http::sequence();
    $searches = Http::sequence();

    foreach ($runs as [$runVersions, $runIssues]) {
        $versions = $runVersions === null
            ? $versions->push(['errorMessages' => ['Boom']], 500)
            : $versions->push($runVersions);
        $searches = $searches->push(['issues' => $runIssues, 'isLast' => true]);
    }

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
        '*/rest/api/3/project/PROJ/versions' => $versions,
        '*/rest/api/3/search/jql*' => $searches,
        '*/rest/api/3/issue/*/transitions' => Http::response(['transitions' => []]),
    ]);
}

/**
 * The Jira version ids an issue is linked to, sorted.
 *
 * @return list<string>
 */
function linkedVersionIds(string $issueJiraId): array
{
    return array_values(JiraIssue::query()
        ->where('jira_id', $issueJiraId)
        ->firstOrFail()
        ->releases()
        ->orderBy('jira_releases.jira_id')
        ->pluck('jira_releases.jira_id')
        ->all());
}

test('it syncs the project versions as releases', function () {
    $user = releaseSyncUser();

    fakeReleaseSyncs([[[
        jiraVersionPayload('20001', 'v1.0', [
            'description' => 'First release',
            'released' => true,
            'startDate' => '2024-01-01',
            'releaseDate' => '2024-02-01',
        ]),
        jiraVersionPayload('20002', 'v2.0', ['overdue' => true, 'releaseDate' => '2024-03-01']),
        jiraVersionPayload('20003', 'v0.9', ['archived' => true, 'released' => true, 'description' => '']),
    ], []]]);

    (new SyncJiraIssuesJob($user))->handle();

    $released = JiraRelease::query()->where('jira_id', '20001')->firstOrFail();

    expect(JiraRelease::query()->where('user_id', $user->id)->count())->toBe(3)
        ->and($released->user_id)->toBe($user->id)
        ->and($released->name)->toBe('v1.0')
        ->and($released->description)->toBe('First release')
        ->and($released->released)->toBeTrue()
        ->and($released->archived)->toBeFalse()
        ->and($released->overdue)->toBeFalse()
        ->and($released->start_date?->toDateString())->toBe('2024-01-01')
        ->and($released->release_date?->toDateString())->toBe('2024-02-01')
        ->and($released->jira_url)->toBe('https://example.atlassian.net/projects/PROJ/versions/20001')
        ->and($released->raw['name'])->toBe('v1.0');

    $overdue = JiraRelease::query()->where('jira_id', '20002')->firstOrFail();
    $archived = JiraRelease::query()->where('jira_id', '20003')->firstOrFail();

    expect($overdue->overdue)->toBeTrue()
        ->and($overdue->released)->toBeFalse()
        ->and($overdue->start_date)->toBeNull()
        ->and($archived->archived)->toBeTrue()
        ->and($archived->description)->toBeNull();
});

test('it requests fix versions and links synced issues to their releases', function () {
    $user = releaseSyncUser();

    fakeReleaseSyncs([[[
        jiraVersionPayload('20001', 'v1.0'),
        jiraVersionPayload('20002', 'v2.0'),
    ], [
        jiraReleaseIssuePayload('1001', 'PROJ-1', ['20001', '20002']),
        jiraReleaseIssuePayload('1002', 'PROJ-2', ['20002']),
        jiraReleaseIssuePayload('1003', 'PROJ-3'),
    ]]]);

    (new SyncJiraIssuesJob($user))->handle();

    expect(linkedVersionIds('1001'))->toBe(['20001', '20002'])
        ->and(linkedVersionIds('1002'))->toBe(['20002'])
        ->and(linkedVersionIds('1003'))->toBe([]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/search/jql')
        && in_array('fixVersions', explode(',', (string) $request['fields']), true));
});

test('re-syncing updates releases in place and deletes versions removed from jira', function () {
    $user = releaseSyncUser();
    $otherUsersRelease = JiraRelease::factory()->create(['jira_id' => '20002']);

    fakeReleaseSyncs([
        [[
            jiraVersionPayload('20001', 'v1.0'),
            jiraVersionPayload('20002', 'v2.0'),
        ], [
            jiraReleaseIssuePayload('1001', 'PROJ-1', ['20001', '20002']),
        ]],
        [[
            jiraVersionPayload('20001', 'v1.0 renamed', ['released' => true]),
        ], []],
    ]);

    (new SyncJiraIssuesJob($user))->handle();

    $kept = JiraRelease::query()->where('user_id', $user->id)->where('jira_id', '20001')->firstOrFail();

    (new SyncJiraIssuesJob($user, force: true))->handle();

    $releases = JiraRelease::query()->where('user_id', $user->id)->get();

    expect($releases)->toHaveCount(1)
        ->and($releases->first()?->id)->toBe($kept->id)
        ->and($releases->first()?->name)->toBe('v1.0 renamed')
        ->and($releases->first()?->released)->toBeTrue()
        ->and(linkedVersionIds('1001'))->toBe(['20001'])
        ->and($otherUsersRelease->fresh())->not->toBeNull();
});

test('an incremental sync replaces the release links of the issues it touches only', function () {
    $user = releaseSyncUser();
    $versions = [
        jiraVersionPayload('20001', 'v1.0'),
        jiraVersionPayload('20002', 'v2.0'),
    ];

    fakeReleaseSyncs([
        [$versions, [
            jiraReleaseIssuePayload('1001', 'PROJ-1', ['20001']),
            jiraReleaseIssuePayload('1002', 'PROJ-2', ['20001']),
            jiraReleaseIssuePayload('1003', 'PROJ-3', ['20001']),
        ]],
        [$versions, [
            jiraReleaseIssuePayload('1001', 'PROJ-1', ['20002']),
            jiraReleaseIssuePayload('1002', 'PROJ-2'),
        ]],
    ]);

    (new SyncJiraIssuesJob($user))->handle();
    (new SyncJiraIssuesJob($user))->handle();

    expect(linkedVersionIds('1001'))->toBe(['20002'])
        ->and(linkedVersionIds('1002'))->toBe([])
        ->and(linkedVersionIds('1003'))->toBe(['20001']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/search/jql')
        && str_contains((string) $request['jql'], 'updated >='));
});

test('a failed versions lookup keeps existing releases and still syncs issues and their links', function () {
    $user = releaseSyncUser();
    JiraRelease::factory()->for($user)->create(['jira_id' => '20001']);

    fakeReleaseSyncs([[null, [
        jiraReleaseIssuePayload('1001', 'PROJ-1', ['20001', '29999']),
    ]]]);

    (new SyncJiraIssuesJob($user))->handle();

    expect(JiraRelease::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(linkedVersionIds('1001'))->toBe(['20001'])
        ->and($user->fresh()?->jira_last_sync_error)->toBeNull();
});
