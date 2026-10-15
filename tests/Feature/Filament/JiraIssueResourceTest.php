<?php

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Filament\Resources\JiraIssues\Pages\ListJiraIssues;
use App\Filament\Resources\JiraIssues\Pages\ViewJiraIssue;
use App\Filament\Resources\JiraIssues\Tables\JiraIssuesTable;
use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraPullRequestState;
use App\Services\Jira\JiraTransitionsCache;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use PHPUnit\Framework\Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

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

/**
 * The HTML of the deferred Jira content infolist from the view page's last
 * Livewire response. Livewire only exposes response effects through the
 * untyped magic `Testable::__get()`.
 *
 * @param  Testable<ViewJiraIssue>  $component
 */
function jiraContentPartial(Testable $component): string
{
    $partial = $component->__get('effects')['partials']['schema.infolist.jiraContent'] ?? null;

    if (!is_string($partial)) {
        Assert::fail('The Jira content partial was not rendered.');
    }

    return $partial;
}

test('guests are redirected to login', function () {
    get(JiraIssueResource::getUrl())
        ->assertRedirect('/login');
});

test('the list page renders for an authenticated user', function () {
    Http::fake();

    actingAs(User::factory()->create());

    livewire(ListJiraIssues::class)->assertOk();
});

test('the table is scoped to the current user', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $mine = JiraIssue::factory()->for($user)->create();
    $theirs = JiraIssue::factory()->for(User::factory())->create();

    livewire(ListJiraIssues::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

test('the sync actions no longer live on the tasks table', function () {
    Http::fake();

    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

    livewire(ListJiraIssues::class)
        ->assertActionDoesNotExist(TestAction::make('sync')->table())
        ->assertActionDoesNotExist(TestAction::make('forceSync')->table());
});

test('opening the update modal reads status options from cache without hitting jira', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

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
    actingAs($user);

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
        '*/rest/api/3/field' => Http::response([]),
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
    actingAs($user);

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
        '*/rest/api/3/field' => Http::response([]),
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
    actingAs($user);

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
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-30',
        'issue_type' => 'Task',
        'status_id' => '1',
        'assignee_account_id' => null,
        'assignee_name' => null,
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
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
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-31',
        'issue_type' => 'Task',
        'status_id' => '1',
        'assignee_account_id' => 'acc-old',
        'assignee_name' => 'Old Assignee',
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
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
    actingAs($user);

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
    actingAs($user);

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

    actingAs(User::factory()->create());

    livewire(ListJiraIssues::class)
        ->assertSee('Jira not connected')
        ->assertSee('Connect');
});

test('clicking the key column opens the task in Jira in a new tab instead of copying it', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->create(['jira_url' => 'https://example.atlassian.net/browse/PROJ-7']);

    livewire(ListJiraIssues::class)
        ->assertTableColumnExists('jira_key', fn (TextColumn $column): bool => $column->getUrl() === 'https://example.atlassian.net/browse/PROJ-7'
            && $column->shouldOpenUrlInNewTab()
            && !$column->isCopyable($column->getState()), $issue);
});

test('bracketed text in the summary is rendered in bold', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

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
    actingAs($user);

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
    actingAs($user);

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

test('the full list includes open and Done tasks by default', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $open = JiraIssue::factory()->for($user)->create();
    $done = JiraIssue::factory()->for($user)->done()->create();

    livewire(ListJiraIssues::class)
        ->assertCanSeeTableRecords([$open, $done]);
});

test('enabling the not-closed filter hides Done tasks on the full list', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $open = JiraIssue::factory()->for($user)->create();
    $done = JiraIssue::factory()->for($user)->done()->create();

    livewire(ListJiraIssues::class)
        ->filterTable('open', true)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$done]);
});

test('the PR column shows the pull request state icon, color and tooltip', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->withPullRequest('MERGED', 2)->create();

    livewire(ListJiraIssues::class)
        ->assertTableColumnStateSet('pr_state', JiraPullRequestState::Merged, $issue)
        ->assertTableColumnExists('pr_state', fn (IconColumn $column): bool => $column->getIcon($column->getState()) === Heroicon::OutlinedCheckCircle
            && $column->getColor($column->getState()) === 'gray'
            && $column->getTooltip() === '2 pull requests, merged', $issue);
});

test('the PR column shows draft requests in blue and unapproved open requests in yellow', function (string $state, string $color, string $tooltip) {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->withPullRequest($state, 1)->create();

    livewire(ListJiraIssues::class)
        ->assertTableColumnExists('pr_state', fn (IconColumn $column): bool => $column->getIcon($column->getState()) === Heroicon::OutlinedArrowPathRoundedSquare
            && $column->getColor($column->getState()) === $color
            && $column->getTooltip() === $tooltip, $issue);
})->with([
    'draft' => ['DRAFT', 'info', '1 pull request, draft'],
    'open' => ['OPEN', 'warning', '1 pull request, open'],
]);

test('the PR column shows approved open or draft requests in green', function (string $state, string $color, string $tooltip) {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->withPullRequest($state, 1, approved: true)->create();

    livewire(ListJiraIssues::class)
        ->assertTableColumnExists('pr_state', fn (IconColumn $column): bool => $column->getColor($column->getState()) === $color
            && $column->getTooltip() === $tooltip, $issue);
})->with([
    'open' => ['OPEN', 'success', '1 pull request, open, approved'],
    'draft' => ['DRAFT', 'success', '1 pull request, draft, approved'],
    'merged' => ['MERGED', 'gray', '1 pull request, merged'],
]);

test('the PR column is empty for a task without pull requests', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create();

    livewire(ListJiraIssues::class)
        ->assertTableColumnExists('pr_state', fn (IconColumn $column): bool => $column->getIcon($column->getState()) === null
            && $column->getTooltip() === null, $issue);
});

test('the PR column hides declined statuses and their tooltip', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->withPullRequest('DECLINED', 2)->create();

    livewire(ListJiraIssues::class)
        ->assertTableColumnExists('pr_state', fn (IconColumn $column): bool => $column->getIcon($column->getState()) === null
            && $column->getTooltip() === null, $issue);
});

test('clicking the PR column opens a modal listing the live pull requests and refreshes the row', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->withPullRequest('MERGED', 1)->create([
        'jira_key' => 'PROJ-70',
        'jira_id' => '10001',
    ]);

    Http::fake([
        '*/rest/dev-status/1.0/issue/summary*' => Http::response([
            'summary' => ['pullrequest' => ['byInstanceType' => ['bitbucket' => ['count' => 2]]]],
        ]),
        '*/rest/dev-status/1.0/issue/detail*' => Http::response([
            'detail' => [['pullRequests' => [
                [
                    'id' => '17',
                    'name' => 'Ship it',
                    'url' => 'https://bitbucket.org/{workspace-uuid}/{repo-uuid}/pull-requests/17',
                    'status' => 'OPEN',
                    'repositoryName' => 'example/app',
                    'author' => ['name' => 'Alice Author'],
                    'source' => ['branch' => 'feature-branch'],
                    'destination' => ['branch' => 'main'],
                    'reviewers' => [['name' => 'Bob Reviewer', 'approved' => true]],
                ],
                ['id' => '18', 'name' => 'Earlier change', 'status' => 'MERGED'],
            ]]],
        ]),
    ]);

    $table = TestAction::make('pullRequests')->table($issue);

    livewire(ListJiraIssues::class)
        ->assertTableColumnExists('pr_state', fn (IconColumn $column): bool => $column->getAction() instanceof Action
            && $column->getAction()->getName() === 'pullRequests'
            && !$column->isClickDisabled(), $issue)
        ->mountAction($table)
        ->assertActionMounted($table)
        ->assertMountedActionModalSee([
            'PROJ-70 pull requests',
            '2 pull requests, open, approved',
            '#17 Ship it',
            'feature-branch → main',
            'Bob Reviewer (approved)',
            '#18 Earlier change',
        ])
        ->assertMountedActionModalSeeHtml('href="https://bitbucket.org/example/app/pull-requests/17"');

    assertDatabaseHas('jira_issues', ['id' => $issue->id, 'pr_state' => 'OPEN', 'pr_count' => 2, 'pr_approved' => true]);
    Http::assertSentCount(2);
});

test('the PR modal keeps the synced summary when Jira cannot be reached', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->withPullRequest('OPEN', 2)->create(['jira_key' => 'PROJ-70']);

    Http::fake(['*/rest/dev-status/1.0/issue/summary*' => Http::response([], 403)]);

    livewire(ListJiraIssues::class)
        ->mountAction(TestAction::make('pullRequests')->table($issue))
        ->assertMountedActionModalSee(['2 pull requests, open', 'Pull request details unavailable']);

    assertDatabaseHas('jira_issues', ['id' => $issue->id, 'pr_state' => 'OPEN', 'pr_count' => 2]);
});

test('the PR modal is unavailable for a task without pull requests', function () {
    Http::fake();

    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->create();

    livewire(ListJiraIssues::class)
        ->assertActionHidden(TestAction::make('pullRequests')->table($issue))
        ->assertTableColumnExists('pr_state', fn (IconColumn $column): bool => $column->isClickDisabled(), $issue);

    Http::assertNothingSent();
});

test('the current-sprint filter shows only active-sprint tasks on the full list', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $inSprint = JiraIssue::factory()->for($user)->inActiveSprint()->create();
    $notInSprint = JiraIssue::factory()->for($user)->create();

    livewire(ListJiraIssues::class)
        ->filterTable('in_active_sprint', true)
        ->assertCanSeeTableRecords([$inSprint])
        ->assertCanNotSeeTableRecords([$notInSprint]);
});

test('the view page renders all task data for the owner', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

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

test('the view page shows the formatted original estimate', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->withEstimate((8 * 3600) + (3 * 3600) + (30 * 60))->create([
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertOk()
        ->assertSee('Original estimate')
        ->assertSee('1d 3h 30m');
});

test('the view page paints without blocking on the deferred jira content', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

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
    actingAs($user);

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

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()]);
    $component->assertOk();
    $component->call('loadDeferredSchema', 'infolist.jiraContent');

    // The description and comments render in the deferred schema's partial.
    $partial = jiraContentPartial($component);

    expect($partial)
        ->toContain('<p>The rendered description.</p>')
        ->toContain('Carol Commenter')
        ->toContain('<p>The first comment.</p>');

    // Both sections share a single deferred request, so Jira is hit exactly once.
    Http::assertSentCount(1);
});

test('the view page renders live subtasks in a table with safe Jira links and escaped summaries', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-70',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-70*' => Http::response([
            'fields' => ['subtasks' => [
                [
                    'key' => 'PROJ-71',
                    'self' => 'javascript:alert(1)',
                    'fields' => [
                        'summary' => 'Child <script>alert(1)</script>',
                        'status' => ['name' => 'Testing', 'statusCategory' => ['name' => 'In Progress']],
                        'priority' => ['name' => 'High'],
                    ],
                ],
                ['key' => 'PROJ-72', 'fields' => ['summary' => 'Another child']],
            ]],
        ]),
    ]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    $partial = jiraContentPartial($component);

    expect($partial)
        ->toContain('Subtasks')
        ->toContain('<table')
        ->toContain('PROJ-71')
        ->toContain('PROJ-72')
        ->toContain('Child &lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('Another child')
        ->toContain('Testing')
        ->toContain('High')
        ->toContain('href="https://example.atlassian.net/browse/PROJ-71"');
    expect($partial)->not->toContain('<script>alert(1)</script>');
    expect($partial)->not->toContain('javascript:alert(1)');

    Http::assertSentCount(1);
});

test('the view page hides the subtasks section when Jira has none or the request fails', function (array $payload, int $status) {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-70',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake(['*/rest/api/3/issue/PROJ-70*' => Http::response($payload, $status)]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    expect(jiraContentPartial($component))
        ->not->toContain('Subtasks');
})->with([
    'no subtasks' => [['fields' => ['subtasks' => []]], 200],
    'subtasks omitted' => [['fields' => []], 200],
    'Jira request fails' => [['errorMessages' => ['Issue not found']], 404],
]);

test('the view page shows Epic child issues even when the native subtasks field is empty', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-70',
        'issue_type' => 'Epic',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-70*' => Http::response(['fields' => ['subtasks' => []]]),
        '*/rest/api/3/search/jql*' => Http::response([
            'issues' => [['key' => 'PROJ-71', 'fields' => ['summary' => 'Epic child task']]],
            'isLast' => true,
        ]),
    ]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    expect(jiraContentPartial($component))
        ->toContain('Subtasks')
        ->toContain('Epic child task')
        ->toContain('PROJ-71');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/search/jql')
        && $request['jql'] === 'parent = "PROJ-70" ORDER BY key ASC');
});

test('an Epic child lookup failure preserves the description and comments', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-70',
        'issue_type' => 'Epic',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-70*' => Http::response(['renderedFields' => ['description' => '<p>Epic description</p>']]),
        '*/rest/api/3/search/jql*' => Http::response([], 403),
    ]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    $partial = jiraContentPartial($component);

    expect($partial)->toContain('Epic description');
    expect($partial)->not->toContain('Subtasks');
});

test('the view page shows the PR summary in an expanded, collapsible section header', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->withPullRequest('MERGED', 1)->create([
        'jira_key' => 'PROJ-70',
        'jira_id' => '10001',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-70*' => Http::response(['fields' => []]),
        '*/rest/dev-status/1.0/issue/summary*' => Http::response([
            'summary' => ['pullrequest' => ['byInstanceType' => ['bitbucket' => ['count' => 1]]]],
        ]),
        '*/rest/dev-status/1.0/issue/detail*' => Http::response([
            'detail' => [['pullRequests' => [[
                'id' => '17',
                'name' => 'Ship <script>alert(1)</script>',
                'url' => 'https://bitbucket.org/example/app/pull-requests/17',
                'status' => 'MERGED',
                'repositoryName' => 'example/app',
                'author' => ['name' => 'Alice Author'],
                'source' => ['branch' => 'feature-branch'],
                'destination' => ['branch' => 'main'],
                'reviewers' => [['name' => 'Bob Reviewer', 'approved' => true]],
                'lastUpdate' => '2026-03-10T12:00:00.000+0000',
            ]]]],
        ]),
    ]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertDontSee('Pull requests')
        ->assertDontSee('1 pull request, merged')
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    $partial = jiraContentPartial($component);

    preg_match_all('/<section\b(.*?)<header\b[^>]*>(.*?)<\/header>/s', $partial, $sections, PREG_SET_ORDER);
    $prSections = array_values(array_filter($sections, fn (array $section): bool => str_contains($section[2], 'Pull requests')));

    expect($prSections)->toHaveCount(1);
    expect($prSections[0][1])->toMatch('/isCollapsed:\s*false/')
        ->toContain('fi-collapsible');
    expect($prSections[0][2])->toContain('fi-section-header-after-ctn')
        ->toContain('1 pull request, merged')
        ->toContain('aria-expanded="true"');

    expect($partial)
        ->toContain('#17 Ship &lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('href="https://bitbucket.org/example/app/pull-requests/17"')
        ->toContain('MERGED')
        ->toContain('example/app')
        ->toContain('feature-branch → main')
        ->toContain('Alice Author')
        ->toContain('Bob Reviewer (approved)');
    expect($partial)->not->toContain('<script>alert(1)</script>');

    Http::assertSentCount(3);
});

test('a development API failure preserves task content and the synced pull request summary', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->withPullRequest('OPEN', 2)->create([
        'jira_key' => 'PROJ-70',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-70*' => Http::response(['renderedFields' => ['description' => '<p>Still readable</p>']]),
        '*/rest/dev-status/1.0/issue/summary*' => Http::response([], 403),
    ]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    expect(jiraContentPartial($component))
        ->toContain('2 pull requests, open')
        ->toContain('Still readable')
        ->toContain('Pull request details unavailable');
});

test('an open PR overrides a stale merged badge and updates the list status', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->withPullRequest('MERGED', 1)->create([
        'jira_key' => 'SUPER-4419',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/SUPER-4419*' => Http::response(['fields' => []]),
        '*/rest/dev-status/1.0/issue/summary*' => Http::response([
            'summary' => ['pullrequest' => ['byInstanceType' => ['bitbucket' => ['count' => 2]]]],
        ]),
        '*/rest/dev-status/1.0/issue/detail*' => Http::response([
            'detail' => [['pullRequests' => [
                ['id' => '934', 'status' => 'MERGED', 'name' => 'Merged change', 'reviewers' => [['name' => 'Alice', 'approved' => true]]],
                ['id' => '937', 'status' => 'OPEN', 'name' => 'Unmerged change', 'reviewers' => [['name' => 'Bob', 'approved' => true]]],
            ]]],
        ]),
    ]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    $partial = jiraContentPartial($component);

    expect($partial)->toContain('2 pull requests, open, approved');
    expect($partial)->not->toContain('1 pull request, merged');

    assertDatabaseHas('jira_issues', ['id' => $issue->id, 'pr_state' => 'OPEN', 'pr_count' => 2, 'pr_approved' => true]);

    livewire(ListJiraIssues::class)
        ->assertTableColumnExists('pr_state', fn (IconColumn $column): bool => $column->getColor($column->getState()) === 'success'
            && $column->getTooltip() === '2 pull requests, open, approved', $issue->fresh());
});

test('declined statuses are omitted from the PR section summary', function (array $statuses, ?string $summary) {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);
    $issue = JiraIssue::factory()->for($user)->withPullRequest('DECLINED', count($statuses))->create([
        'jira_key' => 'PROJ-70',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-70*' => Http::response(['fields' => []]),
        '*/rest/dev-status/1.0/issue/summary*' => Http::response([
            'summary' => ['pullrequest' => ['byInstanceType' => ['bitbucket' => ['count' => count($statuses)]]]],
        ]),
        '*/rest/dev-status/1.0/issue/detail*' => Http::response([
            'detail' => [['pullRequests' => array_map(fn (string $status): array => ['status' => $status], $statuses)]],
        ]),
    ]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    $partial = jiraContentPartial($component);
    preg_match_all('/<header\b[^>]*>(.*?)<\/header>/s', $partial, $headers);
    $prHeader = collect($headers[1])->first(fn (string $header): bool => str_contains($header, 'Pull requests'));

    expect($prHeader)->toBeString()->toContain($summary ?? 'Pull requests');
    expect($prHeader)->not->toContain('declined');
    expect(str_contains($prHeader, 'fi-badge'))->toBe($summary !== null);
})->with([
    'only declined' => [['DECLINED'], null],
    'declined and merged' => [['DECLINED', 'MERGED'], '2 pull requests, merged'],
    'declined and open' => [['DECLINED', 'OPEN'], '2 pull requests, open'],
]);

test('the view page renders each copy-as-markdown button in its header with its own content', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-71',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-71*' => Http::response([
            'renderedFields' => [
                'description' => '<p>Ship the release today.</p>',
                'comment' => [
                    'comments' => [
                        ['body' => '<p>Approved to merge.</p>'],
                        ['body' => '<p>Another comment.</p>'],
                    ],
                ],
            ],
            'fields' => [
                'description' => [
                    'type' => 'doc',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [
                            ['type' => 'text', 'text' => 'Ship the release today.'],
                        ]],
                    ],
                ],
                'comment' => [
                    'comments' => [
                        [
                            'author' => ['displayName' => 'Carol Commenter'],
                            'created' => '2024-03-01T08:00:00.000+0000',
                            'body' => [
                                'type' => 'doc',
                                'content' => [
                                    ['type' => 'paragraph', 'content' => [
                                        ['type' => 'text', 'text' => 'Approved to merge.'],
                                    ]],
                                ],
                            ],
                        ],
                        [
                            'author' => ['displayName' => 'Another Commenter'],
                            'created' => '2024-03-02T08:00:00.000+0000',
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()]);
    $component->assertOk();
    $component->call('loadDeferredSchema', 'infolist.jiraContent');

    $partial = jiraContentPartial($component);

    expect($partial)
        ->toContain('Copy as Markdown')
        ->toContain('Ship the release today.')
        ->toContain('Approved to merge.');

    preg_match_all('/<header\b[^>]*>(.*?)<\/header>/s', html_entity_decode($partial, ENT_QUOTES | ENT_HTML5), $headers);

    expect(implode('\n', $headers[1]))
        ->toContain("window.navigator.clipboard.writeText('Ship the release today.')")
        ->toContain("window.navigator.clipboard.writeText('Approved to merge.')")
        ->toContain("window.navigator.clipboard.writeText('Another comment.')");
});

test('adding a comment from the view page posts the ADF body to jira and notifies', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-80',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-80/comment' => Http::response(['id' => '10500'], 201),
        '*/rest/api/3/issue/PROJ-80*' => Http::response(['renderedFields' => [], 'fields' => []]),
    ]);

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent')
        ->callAction(TestAction::make('addComment')->schemaComponent('jiraContent.comments.commentActions'), data: ['body' => 'Hello **world**'])
        ->assertNotified('Comment added');

    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST' || !str_contains($request->url(), '/rest/api/3/issue/PROJ-80/comment')) {
            return false;
        }

        $paragraph = $request['body']['content'][0]['content'] ?? [];

        foreach ($paragraph as $node) {
            if (($node['text'] ?? null) === 'world' && ($node['marks'][0]['type'] ?? null) === 'strong') {
                return true;
            }
        }

        return false;
    });
});

test('a comment with an editor mention posts an ADF mention of the jira account', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-83',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-83/comment' => Http::response(['id' => '10501'], 201),
        '*/rest/api/3/issue/PROJ-83*' => Http::response(['renderedFields' => [], 'fields' => []]),
    ]);

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent')
        ->callAction(TestAction::make('addComment')->schemaComponent('jiraContent.comments.commentActions'), data: ['body' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'mention', 'attrs' => ['id' => '712020:abc', 'label' => 'Rasa Sataite', 'char' => '@']],
                    ['type' => 'text', 'text' => ' please review'],
                ],
            ]],
        ]])
        ->assertNotified('Comment added');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/rest/api/3/issue/PROJ-83/comment')
        && $request['body']['content'][0]['content'] === [
            ['type' => 'mention', 'attrs' => ['id' => '712020:abc', 'text' => '@Rasa Sataite']],
            ['type' => 'text', 'text' => ' please review'],
        ]);
});

test('the comment editor suggests active people who can view the issue for mentions', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-84',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/user/viewissue/search*' => Http::response([
            ['accountId' => 'acc-1', 'displayName' => 'Rasa Sataite', 'accountType' => 'atlassian', 'active' => true],
            ['accountId' => 'acc-2', 'displayName' => 'Old Rasa', 'accountType' => 'atlassian', 'active' => false],
            ['accountId' => 'acc-3', 'displayName' => 'Rasa Bot', 'accountType' => 'app', 'active' => true],
        ]),
        '*/rest/api/3/issue/PROJ-84*' => Http::response(['renderedFields' => [], 'fields' => []]),
    ]);

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent')
        ->mountAction(TestAction::make('addComment')->schemaComponent('jiraContent.comments.commentActions'));

    $search = fn (string $query): mixed => $component->instance()
        ->callSchemaComponentMethod('mountedActionSchema0.body', 'getMentionSearchResultsForJs', ['search' => $query]);

    expect($search('ras'))->toBe(['acc-1' => 'Rasa Sataite'])
        ->and($search('ras'))->toBe(['acc-1' => 'Rasa Sataite'])
        ->and($search(''))->toBe([]);

    $searches = Http::recorded(fn ($request): bool => str_contains($request->url(), '/rest/api/3/user/viewissue/search'))
        ->map(fn (array $pair): array => [$pair[0]['issueKey'], $pair[0]['query']])
        ->values()
        ->all();

    expect($searches)->toBe([['PROJ-84', 'ras']]);
});

test('a rejected comment from the view page notifies and halts', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-81',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake([
        '*/rest/api/3/issue/PROJ-81/comment' => Http::response(['errorMessages' => ['Nope']], 400),
        '*/rest/api/3/issue/PROJ-81*' => Http::response(['renderedFields' => [], 'fields' => []]),
    ]);

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent')
        ->callAction(TestAction::make('addComment')->schemaComponent('jiraContent.comments.commentActions'), data: ['body' => 'Boom'])
        ->assertNotified('Comment failed')
        ->assertNotNotified('Comment added');
});

test('the add-comment action is shown with a jira connection and hidden without one', function () {
    $connected = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($connected);

    $issue = JiraIssue::factory()->for($connected)->create([
        'jira_key' => 'PROJ-82',
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake(['*/rest/api/3/issue/PROJ-82*' => Http::response(['renderedFields' => [], 'fields' => []])]);

    $connectedComponent = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    expect(jiraContentPartial($connectedComponent))
        ->toContain('Add comment');

    $disconnected = User::factory()->create();
    actingAs($disconnected);

    $theirIssue = JiraIssue::factory()->for($disconnected)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake();

    $disconnectedComponent = livewire(ViewJiraIssue::class, ['record' => $theirIssue->getKey()])
        ->call('loadDeferredSchema', 'infolist.jiraContent');

    expect(jiraContentPartial($disconnectedComponent))
        ->not->toContain('Add comment');
});

test('the view page loads without a jira connection and shows content placeholders', function () {
    $user = User::factory()->create();
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'issue_type' => 'Task',
        'status_id' => '1',
    ]);

    Http::fake();

    $component = livewire(ViewJiraIssue::class, ['record' => $issue->getKey()]);
    $component->assertOk();
    $component->call('loadDeferredSchema', 'infolist.jiraContent');

    $partial = jiraContentPartial($component);

    expect($partial)
        ->toContain('No description')
        ->toContain('No comments');
    expect($partial)->not->toContain('Subtasks');

    Http::assertNothingSent();
});

test('changing status from the view page transitions the issue in jira', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_last_synced_at' => now()]);
    actingAs($user);

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
        '*/rest/api/3/field' => Http::response([]),
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
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-51',
        'issue_type' => 'Task',
        'status_id' => '1',
        'assignee_account_id' => null,
        'assignee_name' => null,
    ]);

    JiraTransitionsCache::put($user, 'Task', '1', []);

    Http::fake([
        '*/rest/api/3/field' => Http::response([]),
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
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create();

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertActionHidden('updateStatusAssignee');
});

test('the view page can toggle the important flag without a jira connection', function () {
    $user = User::factory()->create();
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create(['is_important' => false]);

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->callAction('toggleImportant')
        ->assertNotified('Marked important');

    expect($issue->fresh()->is_important)->toBeTrue();
});

test('opening the view page marks the task as viewed', function () {
    travelTo(Carbon::parse('2026-03-10 12:00:00'));

    $user = User::factory()->create();
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'last_viewed_at' => null,
        'jira_updated_at' => now()->subDay(),
    ]);

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()]);

    expect($issue->fresh()->last_viewed_at->toDateTimeString())->toBe('2026-03-10 12:00:00')
        ->and($issue->fresh()->isUnread())->toBeFalse();
});

test('the view page can snooze the task', function () {
    $user = User::factory()->create();
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create(['snoozed_until' => null]);

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->callAction('snooze', ['duration' => 'tomorrow'])
        ->assertNotified('Snoozed');

    expect($issue->fresh()->snoozed_until->equalTo(now()->addDay()->startOfDay()))->toBeTrue();
});

test('the view page can un-snooze a snoozed task', function () {
    $user = User::factory()->create();
    actingAs($user);

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
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create(['snoozed_until' => null]);

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertActionHidden('unsnooze');
});

test('the view page un-snooze action is hidden when the snooze has expired', function () {
    $user = User::factory()->create();
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->expiredSnooze()->create();

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertActionVisible('snooze')
        ->assertActionHidden('unsnooze');
});

test('the view page snooze action is hidden while the task is snoozed', function () {
    $user = User::factory()->create();
    actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create(['snoozed_until' => now()->addHour()]);

    Http::fake();

    livewire(ViewJiraIssue::class, ['record' => $issue->getKey()])
        ->assertActionHidden('snooze')
        ->assertActionVisible('unsnooze');
});

test('the view page can dismiss the task, which also unstars it', function () {
    $user = User::factory()->create();
    actingAs($user);

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
    actingAs($user);

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

test('the assignee column is bold when the task is assigned to me', function () {
    Http::fake();

    $user = User::factory()->create(['jira_account_id' => 'acc-me']);
    actingAs($user);

    $mine = JiraIssue::factory()->for($user)->create([
        'assignee_account_id' => 'acc-me',
        'assignee_name' => 'Me McGee',
    ]);

    livewire(ListJiraIssues::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertSee('Me McGee')
        ->assertSeeHtml('fi-font-bold');
});

test('the assignee column is not bold when the task is assigned to someone else', function () {
    Http::fake();

    $user = User::factory()->create(['jira_account_id' => 'acc-me']);
    actingAs($user);

    JiraIssue::factory()->for($user)->create([
        'assignee_account_id' => 'acc-other',
        'assignee_name' => 'Otto Otherman',
    ]);

    livewire(ListJiraIssues::class)
        ->assertSee('Otto Otherman')
        ->assertDontSeeHtml('fi-font-bold');
});

test('the priority badge color maps Jira priority names to semantic colors', function () {
    expect(JiraIssuesTable::priorityColor('Highest'))->toBe('danger')
        ->and(JiraIssuesTable::priorityColor('Blocker'))->toBe('danger')
        ->and(JiraIssuesTable::priorityColor('High'))->toBe('warning')
        ->and(JiraIssuesTable::priorityColor('Critical'))->toBe('warning')
        ->and(JiraIssuesTable::priorityColor('Medium'))->toBe('primary')
        ->and(JiraIssuesTable::priorityColor('Low'))->toBe('success')
        ->and(JiraIssuesTable::priorityColor('Lowest'))->toBe('gray')
        ->and(JiraIssuesTable::priorityColor('Wibble'))->toBe('gray')
        ->and(JiraIssuesTable::priorityColor(null))->toBe('gray');
});

test('the priority column is visible by default', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    JiraIssue::factory()->for($user)->create();

    livewire(ListJiraIssues::class)
        ->assertCanRenderTableColumn('priority');
});

test('sorting by priority orders by Jira importance, most important first', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $low = JiraIssue::factory()->for($user)->create(['priority' => 'Low']);
    $highest = JiraIssue::factory()->for($user)->create(['priority' => 'Highest']);
    $medium = JiraIssue::factory()->for($user)->create(['priority' => 'Medium']);

    livewire(ListJiraIssues::class)
        ->sortTable('priority', 'asc')
        ->assertCanSeeTableRecords([$highest, $medium, $low], inOrder: true)
        ->sortTable('priority', 'desc')
        ->assertCanSeeTableRecords([$low, $medium, $highest], inOrder: true);
});

test('sorting by priority orders custom P-prefixed priority names by importance', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $low = JiraIssue::factory()->for($user)->create(['priority' => 'P4 - Low']);
    $highest = JiraIssue::factory()->for($user)->create(['priority' => 'P1 - Highest']);
    $medium = JiraIssue::factory()->for($user)->create(['priority' => 'P3 - Medium']);

    livewire(ListJiraIssues::class)
        ->sortTable('priority', 'asc')
        ->assertCanSeeTableRecords([$highest, $medium, $low], inOrder: true)
        ->sortTable('priority', 'desc')
        ->assertCanSeeTableRecords([$low, $medium, $highest], inOrder: true);
});

test('sorting by assignee orders alphabetically', function () {
    Http::fake();

    $user = User::factory()->create();
    actingAs($user);

    $charlie = JiraIssue::factory()->for($user)->create(['assignee_name' => 'Charlie']);
    $alice = JiraIssue::factory()->for($user)->create(['assignee_name' => 'Alice']);
    $bob = JiraIssue::factory()->for($user)->create(['assignee_name' => 'Bob']);

    livewire(ListJiraIssues::class)
        ->sortTable('assignee_name', 'asc')
        ->assertCanSeeTableRecords([$alice, $bob, $charlie], inOrder: true);
});

test('the status line shows the last sync time when connected', function () {
    $user = User::factory()->withJiraConnection()->create([
        'jira_last_synced_at' => now()->subMinutes(3),
    ]);
    actingAs($user);

    Http::fake();

    livewire(ListJiraIssues::class)
        ->assertSee('Last synced');
});
