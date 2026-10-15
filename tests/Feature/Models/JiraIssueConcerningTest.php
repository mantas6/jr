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

test('an important task is concerning', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->important()->create([
        'assignee_account_id' => null,
    ]);

    expect(concerningIds($user))->toContain($issue->id);
});

test('an unstarred task that was pinned stays concerning until dismissed', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->concerning()->create([
        'assignee_account_id' => null,
        'mentions_me' => false,
    ]);

    expect(concerningIds($user))->toContain($issue->id);

    $issue->update(['dismissed_at' => now(), 'is_important' => false, 'concerning_since' => null]);

    expect(concerningIds($user))->not->toContain($issue->id);
});

test('a task assigned to me that was never dismissed is concerning', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->create([
        'assignee_account_id' => 'acc-me',
        'dismissed_at' => null,
    ]);

    expect(concerningIds($user))->toContain($issue->id);
});

test('a task that mentions me is concerning', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->mentionsMe()->create([
        'assignee_account_id' => null,
    ]);

    expect(concerningIds($user))->toContain($issue->id);
});

test('a dismissed task stays hidden when Jira reports other newer activity', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->mentionsMe()->create([
        'assignee_account_id' => 'acc-me',
        'dismissed_at' => now()->subDay(),
        'jira_updated_at' => now(),
        'assigned_to_me_at' => now()->subWeek(),
        'last_mentioned_at' => now()->subWeek(),
    ]);

    expect(concerningIds($user))->not->toContain($issue->id);
});

test('a dismissed task reappears when I am newly assigned after the dismissal', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->dismissed(now()->subDay())->assignedToMeAt(now())->create([
        'assignee_account_id' => 'acc-me',
    ]);

    expect(concerningIds($user))->toContain($issue->id);
});

test('a dismissed task reappears when I am newly mentioned in a comment after the dismissal', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->dismissed(now()->subDay())->mentionedAt(now())->create([
        'assignee_account_id' => null,
    ]);

    expect(concerningIds($user))->toContain($issue->id);
});

test('a snoozed task is hidden even when important', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->important()->snoozed(now()->addHour())->create();

    expect(concerningIds($user))->not->toContain($issue->id);
});

test('a task whose snooze has expired reappears', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->important()->create([
        'snoozed_until' => now()->subMinute(),
    ]);

    expect(concerningIds($user))->toContain($issue->id);
});

test('a closed task is excluded even when important', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->important()->done()->create();

    expect(concerningIds($user))->not->toContain($issue->id);
});

test('the open and closed scopes split tasks by the Done status category, ignoring case', function () {
    $open = JiraIssue::factory()->create(['status_category' => 'In Progress']);
    $done = JiraIssue::factory()->done()->create();
    $lowercaseDone = JiraIssue::factory()->create(['status_category' => 'done']);

    expect(JiraIssue::query()->open()->pluck('id')->all())->toBe([$open->id])
        ->and(JiraIssue::query()->closed()->pluck('id')->sort()->values()->all())->toBe([$done->id, $lowercaseDone->id]);
});

test('the passed scope keeps dismissed open tasks with null assignee and trigger columns', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->dismissed()->create([
        'assignee_account_id' => null,
        'mentions_me' => false,
        'assigned_to_me_at' => null,
        'last_mentioned_at' => null,
    ]);

    expect(JiraIssue::query()->passed($user)->pluck('id')->all())->toBe([$issue->id]);
});

test('another users concerning tasks are excluded', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $other = User::factory()->create(['jira_account_id' => 'acc-other']);

    JiraIssue::factory()->for($other)->important()->create();

    expect(concerningIds($user))->toBeEmpty();
});

test('a task assigned to someone else with no mention is not concerning', function () {
    $user = User::factory()->create(['jira_account_id' => 'acc-me']);

    $issue = JiraIssue::factory()->for($user)->create([
        'assignee_account_id' => 'acc-someone',
        'is_important' => false,
        'mentions_me' => false,
    ]);

    expect(concerningIds($user))->not->toContain($issue->id);
});
