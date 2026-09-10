<?php

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Filament\Resources\JiraIssues\Pages\ListJiraIssues;
use App\Jobs\SyncJiraIssuesJob;
use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraTransitionsCache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Build a Jira `getIssue` payload for the given key with sensible defaults.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function resourceIssuePayload(string $key, array $fields = []): array
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

test('guests are redirected to login', function () {
    $this->get(JiraIssueResource::getUrl())
        ->assertRedirect('/admin/login');
});

test('the list page renders for an authenticated user', function () {
    Http::fake();

    $this->actingAs(User::factory()->create());

    livewire(ListJiraIssues::class)->assertOk();
});

test('the table is scoped to the current user', function () {
    Http::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $mine = JiraIssue::factory()->for($user)->create();
    $theirs = JiraIssue::factory()->for(User::factory())->create();

    livewire(ListJiraIssues::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

test('the sync header action dispatches the job and notifies', function () {
    Http::fake();
    Queue::fake();

    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    livewire(ListJiraIssues::class)
        ->callTableAction('sync')
        ->assertNotified('Sync queued');

    Queue::assertPushed(SyncJiraIssuesJob::class);
});

test('the sync action is disabled when jira is not connected', function () {
    Http::fake();

    $this->actingAs(User::factory()->create());

    livewire(ListJiraIssues::class)
        ->assertTableActionDisabled('sync');
});

test('rendering the status column reads options from cache without hitting jira', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status' => 'To Do',
        'status_id' => '1',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', [
        ['id' => '31', 'to_id' => '2', 'to_name' => 'In Progress'],
    ]);

    Http::fake();

    livewire(ListJiraIssues::class)
        ->assertCanSeeTableRecords([$issue])
        ->assertTableSelectColumnHasOptions('status_id', [
            '1' => 'To Do',
            '2' => 'In Progress',
        ], $issue);

    Http::assertNothingSent();
});

test('changing the status transitions the issue and refreshes the row', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-20',
        'issue_type' => 'Task',
        'status' => 'To Do',
        'status_id' => '1',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', [
        ['id' => '31', 'to_id' => '2', 'to_name' => 'In Progress'],
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-20/transitions' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-20*' => Http::response(resourceIssuePayload('PROJ-20')),
    ]);

    livewire(ListJiraIssues::class)
        ->call('updateTableColumnState', 'status_id', (string) $issue->getKey(), '2');

    expect($issue->fresh()->status_id)->toBe('2')
        ->and($issue->fresh()->status)->toBe('In Progress');
});

test('a status change trusts the transition target when jira re-fetch is still stale', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-22',
        'issue_type' => 'Task',
        'status' => 'To Do',
        'status_id' => '1',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', [
        ['id' => '31', 'to_id' => '2', 'to_name' => 'In Progress'],
    ]);

    // The transition succeeds, but the immediate re-fetch still reports the old
    // status because Jira's workflow post-functions have not completed yet.
    Http::fake([
        '*/rest/api/3/issue/PROJ-22/transitions' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-22*' => Http::response(resourceIssuePayload('PROJ-22', [
            'status' => [
                'id' => '1',
                'name' => 'To Do',
                'statusCategory' => ['name' => 'To Do'],
            ],
        ])),
    ]);

    livewire(ListJiraIssues::class)
        ->call('updateTableColumnState', 'status_id', (string) $issue->getKey(), '2');

    expect($issue->fresh()->status_id)->toBe('2')
        ->and($issue->fresh()->status)->toBe('In Progress');
});

test('a rejected status change leaves the row unchanged', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-21',
        'issue_type' => 'Task',
        'status' => 'To Do',
        'status_id' => '1',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', [
        ['id' => '31', 'to_id' => '2', 'to_name' => 'In Progress'],
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-21/transitions' => Http::response(['errorMessages' => ['Nope']], 400),
    ]);

    livewire(ListJiraIssues::class)
        ->call('updateTableColumnState', 'status_id', (string) $issue->getKey(), '2')
        ->assertReturned(fn (mixed $result): bool => is_array($result) && isset($result['error']));

    expect($issue->fresh()->status_id)->toBe('1');
});

test('the assignee options list the current user and assignee without hitting jira', function () {
    $user = User::factory()->withJiraConnection()->create([
        'jira_account_id' => 'acc-me',
        'name' => 'Me McGee',
        'jira_last_synced_at' => now(),
    ]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
        'assignee_account_id' => 'acc-old',
        'assignee_name' => 'Old Assignee',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake();

    livewire(ListJiraIssues::class)
        ->assertTableSelectColumnHasOptions('assignee_account_id', [
            'acc-me' => 'Me (Me McGee)',
            'acc-old' => 'Old Assignee',
        ], $issue);

    Http::assertNothingSent();
});

test('searching assignees hits the assignable endpoint with the typed query', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake([
        '*/rest/api/3/user/assignable/search*' => Http::response([
            ['accountId' => 'acc-jane', 'displayName' => 'Jane Jira'],
        ]),
    ]);

    livewire(ListJiraIssues::class)
        ->call('callTableColumnMethod', 'assignee_account_id', (string) $issue->getKey(), 'getOptionsSearchResultsForJs', ['search' => 'jane']);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/rest/api/3/user/assignable/search')
            && $request['query'] === 'jane'
            && $request['project'] === 'PROJ';
    });
});

test('changing the assignee assigns the issue in jira and refreshes the row', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-30',
        'issue_type' => 'Task',
        'status_id' => '1',
        'assignee_account_id' => null,
        'assignee_name' => null,
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake([
        '*/rest/api/3/issue/PROJ-30/assignee' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-30*' => Http::response(resourceIssuePayload('PROJ-30', [
            'assignee' => ['accountId' => 'acc-9', 'displayName' => 'Assignee Nine'],
        ])),
    ]);

    livewire(ListJiraIssues::class)
        ->call('updateTableColumnState', 'assignee_account_id', (string) $issue->getKey(), 'acc-9');

    expect($issue->fresh()->assignee_account_id)->toBe('acc-9')
        ->and($issue->fresh()->assignee_name)->toBe('Assignee Nine');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/rest/api/3/issue/PROJ-30/assignee')
            && $request->method() === 'PUT'
            && $request['accountId'] === 'acc-9';
    });
});

test('selecting Unassigned clears the assignee in jira and locally', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-31',
        'issue_type' => 'Task',
        'status_id' => '1',
        'assignee_account_id' => 'acc-old',
        'assignee_name' => 'Old Assignee',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake([
        '*/rest/api/3/issue/PROJ-31/assignee' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-31*' => Http::response(resourceIssuePayload('PROJ-31', [
            'assignee' => null,
        ])),
    ]);

    livewire(ListJiraIssues::class)
        ->call('updateTableColumnState', 'assignee_account_id', (string) $issue->getKey(), null);

    expect($issue->fresh()->assignee_account_id)->toBeNull()
        ->and($issue->fresh()->assignee_name)->toBeNull();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/rest/api/3/issue/PROJ-31/assignee')
            && $request->method() === 'PUT'
            && array_key_exists('accountId', $request->data())
            && $request['accountId'] === null;
    });
});

test('a rejected assignee change returns an inline error and leaves the row unchanged', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-32',
        'issue_type' => 'Task',
        'status_id' => '1',
        'assignee_account_id' => 'acc-old',
        'assignee_name' => 'Old Assignee',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake([
        '*/rest/api/3/issue/PROJ-32/assignee' => Http::response(['errorMessages' => ['Nope']], 400),
    ]);

    livewire(ListJiraIssues::class)
        ->call('updateTableColumnState', 'assignee_account_id', (string) $issue->getKey(), 'acc-new')
        ->assertReturned(fn (mixed $result): bool => is_array($result) && isset($result['error']));

    expect($issue->fresh()->assignee_account_id)->toBe('acc-old')
        ->and($issue->fresh()->assignee_name)->toBe('Old Assignee');
});

test('the status line prompts to connect when jira is not connected', function () {
    Http::fake();

    $this->actingAs(User::factory()->create());

    livewire(ListJiraIssues::class)
        ->assertSee('Jira not connected')
        ->assertSee('Connect');
});

test('the status line shows the last sync time when connected', function () {
    $user = User::factory()->withJiraConnection()->create([
        'jira_last_synced_at' => now()->subMinutes(3),
    ]);
    $this->actingAs($user);

    Http::fake();

    livewire(ListJiraIssues::class)
        ->assertSee('Last synced');
});
