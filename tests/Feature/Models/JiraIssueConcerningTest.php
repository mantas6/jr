<?php

use App\Models\JiraIssue;
use App\Models\User;

/**
 * Collect the concerning issue ids for the given user.
 *
 * @return array<int, int>
 */
function concerningIds(User $user): array
{
    return JiraIssue::query()
        ->where('user_id', $user->id)
        ->concerningFor($user)
        ->pluck('id')
        ->all();
}

beforeEach(function () {
    $this->user = User::factory()->create(['jira_account_id' => 'acc-me']);
});

test('an important task is concerning', function () {
    $issue = JiraIssue::factory()->for($this->user)->important()->create([
        'assignee_account_id' => null,
    ]);

    expect(concerningIds($this->user))->toContain($issue->id);
});

test('a task assigned to me and updated since dismissal is concerning', function () {
    $issue = JiraIssue::factory()->for($this->user)->create([
        'assignee_account_id' => 'acc-me',
        'dismissed_at' => now()->subDay(),
        'jira_updated_at' => now(),
    ]);

    expect(concerningIds($this->user))->toContain($issue->id);
});

test('a task that mentions me is concerning', function () {
    $issue = JiraIssue::factory()->for($this->user)->mentionsMe()->create([
        'assignee_account_id' => null,
    ]);

    expect(concerningIds($this->user))->toContain($issue->id);
});

test('a dismissed task is hidden until Jira reports newer activity', function () {
    $issue = JiraIssue::factory()->for($this->user)->create([
        'assignee_account_id' => 'acc-me',
        'jira_updated_at' => now()->subDay(),
        'dismissed_at' => now(),
    ]);

    expect(concerningIds($this->user))->not->toContain($issue->id);

    $issue->update(['jira_updated_at' => now()->addMinute()]);

    expect(concerningIds($this->user))->toContain($issue->id);
});

test('a snoozed task is hidden even when important', function () {
    $issue = JiraIssue::factory()->for($this->user)->important()->snoozed(now()->addHour())->create();

    expect(concerningIds($this->user))->not->toContain($issue->id);
});

test('a task whose snooze has expired reappears', function () {
    $issue = JiraIssue::factory()->for($this->user)->important()->create([
        'snoozed_until' => now()->subMinute(),
    ]);

    expect(concerningIds($this->user))->toContain($issue->id);
});

test('another users concerning tasks are excluded', function () {
    $other = User::factory()->create(['jira_account_id' => 'acc-other']);

    JiraIssue::factory()->for($other)->important()->create();

    expect(concerningIds($this->user))->toBeEmpty();
});

test('a task assigned to someone else with no mention is not concerning', function () {
    $issue = JiraIssue::factory()->for($this->user)->create([
        'assignee_account_id' => 'acc-someone',
        'is_important' => false,
        'mentions_me' => false,
    ]);

    expect(concerningIds($this->user))->not->toContain($issue->id);
});
