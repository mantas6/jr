<?php

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Filament\Resources\JiraIssues\Pages\ListJiraIssues;
use App\Filament\Resources\JiraIssues\Pages\ViewJiraIssue;
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
        ->assertRedirect('/login');
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

test('opening the update modal reads status options from cache without hitting jira', function () {
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

    // Submitting with the current status validates the status select's options,
    // which are read from the transitions cache — so no Jira call is made.
    livewire(ListJiraIssues::class)
        ->assertCanSeeTableRecords([$issue])
        ->callTableAction('updateStatusAssignee', $issue, data: ['status_id' => '1'])
        ->assertNotified('Task updated');

    Http::assertNothingSent();
});

test('changing the status via the update modal transitions the issue and refreshes the row', function () {
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
        ->callTableAction('updateStatusAssignee', $issue, data: ['status_id' => '2'])
        ->assertNotified('Task updated');

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
        ->callTableAction('updateStatusAssignee', $issue, data: ['status_id' => '2'])
        ->assertNotified('Task updated');

    expect($issue->fresh()->status_id)->toBe('2')
        ->and($issue->fresh()->status)->toBe('In Progress');
});

test('a rejected status change notifies and leaves the row unchanged', function () {
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
        ->callTableAction('updateStatusAssignee', $issue, data: ['status_id' => '2'])
        ->assertNotified('Update failed');

    expect($issue->fresh()->status_id)->toBe('1');
});

test('changing the assignee via the update modal assigns the issue in jira and refreshes the row', function () {
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
        ->callTableAction('updateStatusAssignee', $issue, data: ['status_id' => '1', 'assignee_account_id' => 'acc-9'])
        ->assertNotified('Task updated');

    expect($issue->fresh()->assignee_account_id)->toBe('acc-9')
        ->and($issue->fresh()->assignee_name)->toBe('Assignee Nine');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/rest/api/3/issue/PROJ-30/assignee')
            && $request->method() === 'PUT'
            && $request['accountId'] === 'acc-9';
    });
});

test('selecting Unassigned via the update modal clears the assignee in jira and locally', function () {
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
        ->callTableAction('updateStatusAssignee', $issue, data: ['status_id' => '1', 'assignee_account_id' => null])
        ->assertNotified('Task updated');

    expect($issue->fresh()->assignee_account_id)->toBeNull()
        ->and($issue->fresh()->assignee_name)->toBeNull();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/rest/api/3/issue/PROJ-31/assignee')
            && $request->method() === 'PUT'
            && array_key_exists('accountId', $request->data())
            && $request['accountId'] === null;
    });
});

test('a rejected assignee change notifies and leaves the row unchanged', function () {
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
        ->callTableAction('updateStatusAssignee', $issue, data: ['status_id' => '1', 'assignee_account_id' => 'acc-new'])
        ->assertNotified('Update failed');

    expect($issue->fresh()->assignee_account_id)->toBe('acc-old')
        ->and($issue->fresh()->assignee_name)->toBe('Old Assignee');
});

test('the update modal dismiss toggle dismisses and unstars the task', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status' => 'To Do',
        'status_id' => '1',
        'is_important' => true,
        'dismissed_at' => null,
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake();

    livewire(ListJiraIssues::class)
        ->callTableAction('updateStatusAssignee', $issue, data: ['status_id' => '1', 'dismiss' => true])
        ->assertNotified('Task updated');

    expect($issue->fresh()->dismissed_at)->not->toBeNull()
        ->and($issue->fresh()->is_important)->toBeFalse();
});

test('the status line prompts to connect when jira is not connected', function () {
    Http::fake();

    $this->actingAs(User::factory()->create());

    livewire(ListJiraIssues::class)
        ->assertSee('Jira not connected')
        ->assertSee('Connect');
});

test('bracketed text in the summary is rendered in bold', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
        'summary' => '[API] Fix broken login',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake();

    livewire(ListJiraIssues::class)
        ->assertSeeHtml('<strong>[API]</strong> Fix broken login');
});

test('the sprint column renders each sprint name as a badge', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
        'sprints' => ['Alpha Sprint', 'Beta Sprint'],
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake();

    livewire(ListJiraIssues::class)
        ->assertCanSeeTableRecords([$issue])
        ->toggleAllTableColumns()
        ->assertSee('Alpha Sprint')
        ->assertSee('Beta Sprint');
});

test('the sprint filter narrows to the selected sprint without partial-name false matches', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $inSprintOne = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
        'sprints' => ['Sprint 1'],
    ]);
    $inSprintTen = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
        'sprints' => ['Sprint 10'],
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake();

    livewire(ListJiraIssues::class)
        ->filterTable('sprint', 'Sprint 1')
        ->assertCanSeeTableRecords([$inSprintOne])
        ->assertCanNotSeeTableRecords([$inSprintTen]);
});

test('the view page renders all task data for the owner', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'summary' => 'Detailed task summary',
        'issue_type' => 'Task',
        'status_id' => '1',
        'reporter_name' => 'Rita Reporter',
        'sprints' => ['Sprint Alpha'],
    ]);

    Http::fake(['*' => Http::response(['renderedFields' => [], 'fields' => []])]);

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertOk()
        ->assertSee('Detailed task summary')
        ->assertSee('Rita Reporter')
        ->assertSee('Sprint Alpha');

    // The Jira description/comments are deferred, so nothing is fetched on mount.
    Http::assertNothingSent();
});

test('the view page paints without blocking on the deferred jira content', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake(['*' => Http::response(['renderedFields' => [], 'fields' => []])]);

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertOk();

    Http::assertNothingSent();
});

test('the view page renders the live description and comments from jira', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-70',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-70*' => Http::response([
            'renderedFields' => [
                'description' => '<p>The rendered description.</p>',
                'comment' => [
                    'comments' => [
                        ['body' => '<p>The first comment.</p>'],
                    ],
                ],
            ],
            'fields' => [
                'comment' => [
                    'comments' => [
                        ['author' => ['displayName' => 'Carol Commenter'], 'created' => '2024-03-01T08:00:00.000+0000'],
                    ],
                ],
            ],
        ]),
    ]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertOk()
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    // The description and comments render in the deferred schema's partial.
    $partial = $component->effects['partials']['schema.infolist.jiraContent'];

    expect($partial)
        ->toContain('<p>The rendered description.</p>')
        ->toContain('Carol Commenter')
        ->toContain('<p>The first comment.</p>');

    // Both sections share a single deferred request, so Jira is hit exactly once.
    Http::assertSentCount(1);
});

test('the view page loads without a jira connection and shows content placeholders', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake();

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertOk()
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    $partial = $component->effects['partials']['schema.infolist.jiraContent'];

    expect($partial)
        ->toContain('No description')
        ->toContain('No comments');

    Http::assertNothingSent();
});

test('changing status from the view page transitions the issue in jira', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-50',
        'issue_type' => 'Task',
        'status' => 'To Do',
        'status_id' => '1',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', [
        ['id' => '31', 'to_id' => '2', 'to_name' => 'In Progress'],
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-50/transitions' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-50*' => Http::response(resourceIssuePayload('PROJ-50')),
    ]);

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->callAction('updateStatusAssignee', ['status_id' => '2'])
        ->assertNotified('Task updated');

    expect($issue->fresh()->status_id)->toBe('2')
        ->and($issue->fresh()->status)->toBe('In Progress');
});

test('reassigning from the view page assigns the issue in jira', function () {
    $user = User::factory()->withJiraConnection()->create([
        'jira_account_id' => 'acc-me',
        'name' => 'Me McGee',
        'jira_last_synced_at' => now(),
    ]);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-51',
        'issue_type' => 'Task',
        'status_id' => '1',
        'assignee_account_id' => null,
        'assignee_name' => null,
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake([
        '*/rest/api/3/issue/PROJ-51/assignee' => Http::response([], 204),
        '*/rest/api/3/issue/PROJ-51*' => Http::response(resourceIssuePayload('PROJ-51', [
            'assignee' => ['accountId' => 'acc-me', 'displayName' => 'Me McGee'],
        ])),
    ]);

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->callAction('updateStatusAssignee', ['status_id' => '1', 'assignee_account_id' => 'acc-me'])
        ->assertNotified('Task updated');

    expect($issue->fresh()->assignee_account_id)->toBe('acc-me')
        ->and($issue->fresh()->assignee_name)->toBe('Me McGee');
});

test('the view page edit actions are hidden without a jira connection', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create();

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertActionHidden('updateStatusAssignee');
});

test('the view page can toggle the important flag without a jira connection', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create(['is_important' => false]);

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->callAction('toggleImportant')
        ->assertNotified('Marked important');

    expect($issue->fresh()->is_important)->toBeTrue();
});

test('the view page can snooze the task', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create(['snoozed_until' => null]);

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->callAction('snooze', ['duration' => 'tomorrow'])
        ->assertNotified('Snoozed');

    expect($issue->fresh()->snoozed_until->equalTo(now()->addDay()->startOfDay()))->toBeTrue();
});

test('the view page can un-snooze a snoozed task', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create(['snoozed_until' => now()->addHour()]);

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertActionVisible('unsnooze')
        ->callAction('unsnooze')
        ->assertNotified('Un-snoozed');

    expect($issue->fresh()->snoozed_until)->toBeNull();
});

test('the view page un-snooze action is hidden when the task is not snoozed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create(['snoozed_until' => null]);

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertActionHidden('unsnooze');
});

test('the view page can dismiss the task, which also unstars it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create(['dismissed_at' => null, 'is_important' => true]);

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->callAction('dismiss')
        ->assertNotified('Dismissed');

    expect($issue->fresh()->dismissed_at)->not->toBeNull()
        ->and($issue->fresh()->is_important)->toBeFalse();
});

test('sorting the jira updated column orders by the underlying date, not the humanized text', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    $this->actingAs($user);

    Http::fake();

    $oldest = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
        'jira_updated_at' => '2020-01-01 00:00:00',
    ]);
    $middle = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
        'jira_updated_at' => '2023-06-15 00:00:00',
    ]);
    $newest = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
        'jira_updated_at' => '2025-12-31 00:00:00',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    livewire(ListJiraIssues::class)
        ->sortTable('jira_updated_at', 'asc')
        ->assertCanSeeTableRecords([$oldest, $middle, $newest], inOrder: true);
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
