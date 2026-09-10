<?php

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Filament\Resources\JiraIssues\Pages\ListConcerningTasks;
use App\Filament\Resources\JiraIssues\Pages\ListJiraIssues;
use App\Models\JiraIssue;
use App\Models\User;
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

test('starring a task from the full list marks it important', function () {
    $issue = JiraIssue::factory()->for($this->user)->create(['is_important' => false]);

    livewire(ListJiraIssues::class)
        ->callTableAction('toggleImportant', $issue);

    expect($issue->fresh()->is_important)->toBeTrue();

    livewire(ListJiraIssues::class)
        ->callTableAction('toggleImportant', $issue->fresh());

    expect($issue->fresh()->is_important)->toBeFalse();
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

test('dismissing a task stamps dismissed_at and unstars it', function () {
    $issue = JiraIssue::factory()->for($this->user)->create([
        'assignee_account_id' => 'acc-me',
        'jira_updated_at' => now(),
        'dismissed_at' => null,
        'is_important' => true,
    ]);

    livewire(ListConcerningTasks::class)
        ->callTableAction('dismiss', $issue);

    expect($issue->fresh()->dismissed_at)->not->toBeNull()
        ->and($issue->fresh()->is_important)->toBeFalse();
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

test('bulk dismissing stamps and unstars every selected task', function () {
    $issue = JiraIssue::factory()->for($this->user)->create([
        'assignee_account_id' => 'acc-me',
        'jira_updated_at' => now(),
        'dismissed_at' => null,
        'is_important' => true,
    ]);

    livewire(ListConcerningTasks::class)
        ->callTableBulkAction('bulkDismiss', collect([$issue]));

    expect($issue->fresh()->dismissed_at)->not->toBeNull()
        ->and($issue->fresh()->is_important)->toBeFalse();
});

test('the bulk actions are hidden on the full task list', function () {
    JiraIssue::factory()->for($this->user)->important()->create();

    livewire(ListJiraIssues::class)
        ->assertTableBulkActionHidden('bulkStar')
        ->assertTableBulkActionHidden('bulkSnooze')
        ->assertTableBulkActionHidden('bulkDismiss');
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
