<?php

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Filament\Resources\JiraIssues\Pages\ListConcerningTasks;
use App\Filament\Resources\JiraIssues\Pages\ListJiraIssues;
use App\Models\JiraIssue;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();

    // A local Jira account id is enough to drive the concerning scope; without a
    // full connection the status/assignee columns stay local and hit no HTTP.
    $this->user = User::factory()->create(['jira_account_id' => 'acc-me']);
    $this->actingAs($this->user);
});

test('the concerning page only shows tasks that need attention', function () {
    $important = JiraIssue::factory()->for($this->user)->important()->create();
    $quiet = JiraIssue::factory()->for($this->user)->create([
        'assignee_account_id' => 'acc-someone',
        'is_important' => false,
        'mentions_me' => false,
    ]);

    livewire(ListConcerningTasks::class)
        ->assertCanSeeTableRecords([$important])
        ->assertCanNotSeeTableRecords([$quiet]);
});

test('the concerning page hides snoozed tasks by default', function () {
    $active = JiraIssue::factory()->for($this->user)->important()->create();
    $snoozed = JiraIssue::factory()->for($this->user)->important()->snoozed(now()->addHour())->create();

    livewire(ListConcerningTasks::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$snoozed]);
});

test('the snooze filter can reveal only snoozed tasks on the concerning page', function () {
    $active = JiraIssue::factory()->for($this->user)->important()->create();
    $snoozed = JiraIssue::factory()->for($this->user)->important()->snoozed(now()->addHour())->create();

    livewire(ListConcerningTasks::class)
        ->filterTable('snoozed', 'snoozed')
        ->assertCanSeeTableRecords([$snoozed])
        ->assertCanNotSeeTableRecords([$active]);
});

test('clearing the snooze filter shows both active and snoozed concerning tasks', function () {
    $active = JiraIssue::factory()->for($this->user)->important()->create();
    $snoozed = JiraIssue::factory()->for($this->user)->important()->snoozed(now()->addHour())->create();

    livewire(ListConcerningTasks::class)
        ->filterTable('snoozed', null)
        ->assertCanSeeTableRecords([$active, $snoozed]);
});

test('the snooze filter is hidden on the full task list', function () {
    livewire(ListConcerningTasks::class)
        ->assertTableFilterVisible('snoozed');

    livewire(ListJiraIssues::class)
        ->assertTableFilterHidden('snoozed');
});

test('the not-closed filter is off by default on the concerning page but can hide Done tasks', function () {
    $open = JiraIssue::factory()->for($this->user)->important()->create();
    $done = JiraIssue::factory()->for($this->user)->important()->done()->create();

    // The concerning list surfaces tasks that need attention regardless of status,
    // so Done tasks stay visible until the filter is applied by hand.
    livewire(ListConcerningTasks::class)
        ->assertCanSeeTableRecords([$open, $done]);

    livewire(ListConcerningTasks::class)
        ->filterTable('open', true)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$done]);
});

test('the current-sprint filter shows only active-sprint tasks on the concerning page', function () {
    $inSprint = JiraIssue::factory()->for($this->user)->important()->inActiveSprint()->create();
    $notInSprint = JiraIssue::factory()->for($this->user)->important()->create();

    livewire(ListConcerningTasks::class)
        ->filterTable('in_active_sprint', true)
        ->assertCanSeeTableRecords([$inSprint])
        ->assertCanNotSeeTableRecords([$notInSprint]);
});

test('the concerning page only exposes the snooze, not-closed and current-sprint filters', function () {
    livewire(ListConcerningTasks::class)
        ->assertTableFilterVisible('snoozed')
        ->assertTableFilterVisible('open')
        ->assertTableFilterVisible('in_active_sprint')
        ->assertTableFilterHidden('status')
        ->assertTableFilterHidden('issue_type')
        ->assertTableFilterHidden('assignee_name')
        ->assertTableFilterHidden('sprint');
});

test('the full task list exposes the trimmed filters plus not-closed and current-sprint', function () {
    livewire(ListJiraIssues::class)
        ->assertTableFilterVisible('status')
        ->assertTableFilterVisible('issue_type')
        ->assertTableFilterVisible('assignee_name')
        ->assertTableFilterVisible('sprint')
        ->assertTableFilterVisible('open')
        ->assertTableFilterVisible('in_active_sprint')
        ->assertTableFilterHidden('snoozed');
});

test('starring a task from the full list marks it important', function () {
    $issue = JiraIssue::factory()->for($this->user)->create(['is_important' => false]);

    livewire(ListJiraIssues::class)
        ->callTableAction('toggleImportant', $issue);

    expect($issue->fresh()->is_important)->toBeTrue();

    livewire(ListJiraIssues::class)
        ->callTableAction('toggleImportant', $issue->fresh());

    expect($issue->fresh()->is_important)->toBeFalse();
});

test('unstarring a task keeps it on the concerning list until dismissed', function () {
    $issue = JiraIssue::factory()->for($this->user)->create([
        'assignee_account_id' => 'acc-someone',
        'mentions_me' => false,
        'is_important' => true,
        'concerning_since' => now(),
    ]);

    livewire(ListConcerningTasks::class)
        ->callTableAction('toggleImportant', $issue)
        ->assertCanSeeTableRecords([$issue->fresh()]);

    expect($issue->fresh()->is_important)->toBeFalse()
        ->and($issue->fresh()->concerning_since)->not->toBeNull();

    livewire(ListConcerningTasks::class)
        ->callTableAction('dismiss', $issue);

    expect($issue->fresh()->concerning_since)->toBeNull();

    livewire(ListConcerningTasks::class)
        ->assertCanNotSeeTableRecords([$issue->fresh()]);
});

test('snoozing a task sets the snooze window at midnight on the concerning list', function () {
    $issue = JiraIssue::factory()->for($this->user)->important()->create();

    livewire(ListConcerningTasks::class)
        ->callTableAction('snooze', $issue, data: ['duration' => 'tomorrow']);

    expect($issue->fresh()->snoozed_until)->not->toBeNull()
        ->and($issue->fresh()->snoozed_until->equalTo(now()->addDay()->startOfDay()))->toBeTrue();
});

test('each snooze duration maps to midnight of the expected day', function (string $duration, Closure $expected) {
    $issue = JiraIssue::factory()->for($this->user)->important()->create();

    livewire(ListConcerningTasks::class)
        ->callTableAction('snooze', $issue, data: ['duration' => $duration]);

    expect($issue->fresh()->snoozed_until->equalTo($expected()))->toBeTrue();
})->with([
    ['tomorrow', fn () => now()->addDay()->startOfDay()],
    ['2_days', fn () => now()->addDays(2)->startOfDay()],
    ['next_week', fn () => now()->addWeek()->startOfDay()],
]);

test('dismissing a task stamps dismissed_at, unstars it, and clears its pin', function () {
    $issue = JiraIssue::factory()->for($this->user)->create([
        'assignee_account_id' => 'acc-me',
        'jira_updated_at' => now(),
        'dismissed_at' => null,
        'is_important' => true,
        'concerning_since' => now(),
    ]);

    livewire(ListConcerningTasks::class)
        ->callTableAction('dismiss', $issue);

    expect($issue->fresh()->dismissed_at)->not->toBeNull()
        ->and($issue->fresh()->is_important)->toBeFalse()
        ->and($issue->fresh()->concerning_since)->toBeNull();
});

test('un-snoozing a task from the full list clears the snooze window', function () {
    // Snoozed tasks are hidden from the concerning list, so the un-snooze action
    // surfaces on the full task list where the record is still visible.
    $issue = JiraIssue::factory()->for($this->user)->important()->snoozed(now()->addHour())->create();

    livewire(ListJiraIssues::class)
        ->assertTableActionVisible('unsnooze', $issue)
        ->callTableAction('unsnooze', $issue);

    expect($issue->fresh()->snoozed_until)->toBeNull();
});

test('the snooze and dismiss actions are hidden on the full task list', function () {
    $issue = JiraIssue::factory()->for($this->user)->important()->create();

    livewire(ListJiraIssues::class)
        ->assertTableActionVisible('toggleImportant', $issue)
        ->assertTableActionHidden('snooze', $issue)
        ->assertTableActionHidden('dismiss', $issue)
        ->assertTableActionHidden('unsnooze', $issue);
});

test('the un-snooze action is hidden when the task is not snoozed', function () {
    $issue = JiraIssue::factory()->for($this->user)->important()->create(['snoozed_until' => null]);

    livewire(ListConcerningTasks::class)
        ->assertTableActionVisible('snooze', $issue)
        ->assertTableActionHidden('unsnooze', $issue);
});

test('an expired snooze hides the un-snooze action and shows the snooze action', function () {
    $issue = JiraIssue::factory()->for($this->user)->important()->expiredSnooze()->create();

    livewire(ListConcerningTasks::class)
        ->assertCanSeeTableRecords([$issue])
        ->assertTableActionVisible('snooze', $issue)
        ->assertTableActionHidden('unsnooze', $issue);
});

test('an active snooze hides the snooze action and shows the un-snooze action', function () {
    $issue = JiraIssue::factory()->for($this->user)->important()->snoozed(now()->addHour())->create();

    livewire(ListConcerningTasks::class)
        ->filterTable('snoozed', 'snoozed')
        ->assertCanSeeTableRecords([$issue])
        ->assertTableActionHidden('snooze', $issue)
        ->assertTableActionVisible('unsnooze', $issue);
});

test('bulk starring marks every selected task important', function () {
    $issues = JiraIssue::factory()->for($this->user)->mentionsMe()->count(3)->create();

    livewire(ListConcerningTasks::class)
        ->callTableBulkAction('bulkStar', $issues);

    $issues->each(fn (JiraIssue $issue) => expect($issue->fresh()->is_important)->toBeTrue());
});

test('bulk snoozing sets the window on every selected task', function () {
    $issues = JiraIssue::factory()->for($this->user)->important()->count(3)->create();

    livewire(ListConcerningTasks::class)
        ->callTableBulkAction('bulkSnooze', $issues, data: ['duration' => 'next_week']);

    $issues->each(function (JiraIssue $issue): void {
        expect($issue->fresh()->snoozed_until)->not->toBeNull()
            ->and($issue->fresh()->snoozed_until->equalTo(now()->addWeek()->startOfDay()))->toBeTrue();
    });
});

test('bulk dismissing stamps, unstars, and clears the pin on every selected task', function () {
    $issue = JiraIssue::factory()->for($this->user)->create([
        'assignee_account_id' => 'acc-me',
        'jira_updated_at' => now(),
        'dismissed_at' => null,
        'is_important' => true,
        'concerning_since' => now(),
    ]);

    livewire(ListConcerningTasks::class)
        ->callTableBulkAction('bulkDismiss', collect([$issue]));

    expect($issue->fresh()->dismissed_at)->not->toBeNull()
        ->and($issue->fresh()->is_important)->toBeFalse()
        ->and($issue->fresh()->concerning_since)->toBeNull();
});

test('bulk exporting downloads the selected tasks as a text file', function () {
    $this->travelTo(Carbon::parse('2026-02-01 09:00:00'));

    // The table sorts by jira_updated_at desc, so the newer task exports first.
    $a = JiraIssue::factory()->for($this->user)->important()->create([
        'jira_url' => 'https://site.atlassian.net/browse/PROJ-1',
        'summary' => 'First task',
        'jira_updated_at' => now(),
    ]);
    $b = JiraIssue::factory()->for($this->user)->important()->create([
        'jira_url' => 'https://site.atlassian.net/browse/PROJ-2',
        'summary' => 'Second task',
        'jira_updated_at' => now()->subMinute(),
    ]);

    $expected = "https://site.atlassian.net/browse/PROJ-1\nFirst task\n\n".
        "https://site.atlassian.net/browse/PROJ-2\nSecond task\n";

    livewire(ListConcerningTasks::class)
        ->callTableBulkAction('bulkExport', [$a, $b])
        ->assertFileDownloaded('concerning-tasks-2026-02-01.txt', $expected, 'text/plain; charset=UTF-8');
});

test('the bulk actions are hidden on the full task list', function () {
    JiraIssue::factory()->for($this->user)->important()->create();

    livewire(ListJiraIssues::class)
        ->assertTableBulkActionHidden('bulkStar')
        ->assertTableBulkActionHidden('bulkSnooze')
        ->assertTableBulkActionHidden('bulkDismiss')
        ->assertTableBulkActionHidden('bulkExport');
});

test('the resource registers two navigation items with concerning tasks on top', function () {
    JiraIssue::factory()->for($this->user)->important()->count(2)->create();

    $items = JiraIssueResource::getNavigationItems();

    expect($items)->toHaveCount(2)
        ->and($items[0]->getLabel())->toBe('Concerning Tasks')
        ->and($items[0]->getSort())->toBe(10)
        ->and($items[0]->getBadge())->toBe('2')
        ->and($items[1]->getLabel())->toBe('Tasks')
        ->and($items[1]->getSort())->toBe(11);
});

test('the concerning badge is null when nothing needs attention', function () {
    $items = JiraIssueResource::getNavigationItems();

    expect($items[0]->getBadge())->toBeNull();
});

test('the concerning page shows the last sync time when connected', function () {
    $user = User::factory()->withJiraConnection()->create([
        'jira_account_id' => 'acc-me',
        'jira_last_synced_at' => now()->subMinutes(3),
    ]);
    $this->actingAs($user);

    livewire(ListConcerningTasks::class)
        ->assertSee('Last synced');
});

/**
 * Build a minimal single-issue Jira payload for the given key.
 *
 * @return array<string, mixed>
 */
function addTasksPayload(string $key): array
{
    return [
        'id' => (string) fake()->unique()->numberBetween(50000, 99999),
        'key' => $key,
        'fields' => [
            'summary' => "Summary for {$key}",
            'status' => [
                'id' => '1',
                'name' => 'To Do',
                'statusCategory' => ['name' => 'To Do'],
            ],
            'issuetype' => ['name' => 'Task'],
            'priority' => ['name' => 'Medium'],
            'assignee' => null,
            'reporter' => ['displayName' => 'Reporter'],
            'created' => '2024-01-01T00:00:00.000+0000',
            'updated' => '2024-02-01T00:00:00.000+0000',
        ],
    ];
}

test('the add-tasks action is disabled without a Jira connection', function () {
    livewire(ListConcerningTasks::class)
        ->assertActionDisabled('addTasks');
});

test('adding a new key fetches it from Jira and pins it to the concerning list', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_account_id' => 'acc-me']);
    $this->actingAs($user);

    // Reset the shared beforeEach catch-all fake so the specific stub takes effect.
    Http::swap(new Factory());
    Http::fake([
        '*/rest/api/3/issue/PROJ-500*' => Http::response(addTasksPayload('PROJ-500')),
    ]);

    livewire(ListConcerningTasks::class)
        ->callAction('addTasks', data: ['tasks' => 'https://example.atlassian.net/browse/PROJ-500'])
        ->assertHasNoFormErrors()
        ->assertNotified();

    $issue = JiraIssue::query()->where('user_id', $user->id)->where('jira_key', 'PROJ-500')->first();

    expect($issue)->not->toBeNull()
        ->and($issue->concerning_since)->not->toBeNull();

    livewire(ListConcerningTasks::class)
        ->assertCanSeeTableRecords([$issue]);
});

test('adding an existing key re-surfaces it without contacting Jira', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_account_id' => 'acc-me']);
    $this->actingAs($user);

    $issue = JiraIssue::factory()->for($user)->create([
        'jira_key' => 'PROJ-1',
        'concerning_since' => null,
        'dismissed_at' => now()->subDay(),
        'snoozed_until' => now()->addDay(),
    ]);

    Http::fake();

    livewire(ListConcerningTasks::class)
        ->callAction('addTasks', data: ['tasks' => 'PROJ-1'])
        ->assertHasNoFormErrors()
        ->assertNotified();

    $issue->refresh();

    expect($issue->concerning_since)->not->toBeNull()
        ->and($issue->dismissed_at)->toBeNull()
        ->and($issue->snoozed_until)->toBeNull();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/rest/api/3/issue/'));
});

test('a not-found key is reported as failed while valid keys are still added', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_account_id' => 'acc-me']);
    $this->actingAs($user);

    // Reset the shared beforeEach catch-all fake so the specific stubs take effect.
    Http::swap(new Factory());
    Http::fake([
        '*/rest/api/3/issue/PROJ-404*' => Http::response(['errorMessages' => ['Issue does not exist']], 404),
        '*/rest/api/3/issue/PROJ-200*' => Http::response(addTasksPayload('PROJ-200')),
    ]);

    livewire(ListConcerningTasks::class)
        ->callAction('addTasks', data: ['tasks' => "PROJ-200\nPROJ-404"])
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(JiraIssue::query()->where('user_id', $user->id)->where('jira_key', 'PROJ-200')->exists())->toBeTrue()
        ->and(JiraIssue::query()->where('user_id', $user->id)->where('jira_key', 'PROJ-404')->exists())->toBeFalse();
});

test('submitting the add-tasks form without input shows a validation error', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_account_id' => 'acc-me']);
    $this->actingAs($user);

    livewire(ListConcerningTasks::class)
        ->callAction('addTasks', data: ['tasks' => ''])
        ->assertHasFormErrors(['tasks' => 'required']);
});

test('garbage input adds nothing and warns that no keys were found', function () {
    $user = User::factory()->withJiraConnection()->create(['jira_account_id' => 'acc-me']);
    $this->actingAs($user);

    Http::fake();

    livewire(ListConcerningTasks::class)
        ->callAction('addTasks', data: ['tasks' => 'just some words'])
        ->assertActionHalted('addTasks')
        ->assertNotified();

    expect(JiraIssue::query()->where('user_id', $user->id)->count())->toBe(0);
});
