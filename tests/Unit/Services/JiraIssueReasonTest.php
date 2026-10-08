<?php

use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraIssueReason;
use Illuminate\Support\Carbon;

test('the reason is the most recent trigger, falling back to the current state', function (Closure $attributes, ?JiraIssueReason $expected) {
    $this->travelTo(Carbon::parse('2026-03-10 12:00:00'));

    $issue = JiraIssue::factory()->make([
        'user_id' => 1,
        'assignee_account_id' => null,
        'is_important' => false,
        'mentions_me' => false,
        ...$attributes(),
    ]);

    expect(JiraIssueReason::for($issue, User::factory()->make(['jira_account_id' => 'acc-me'])))->toBe($expected);
})->with([
    'expired snooze' => [fn () => ['is_important' => true, 'concerning_since' => now()->subDay(), 'snoozed_until' => now()->subHour()], JiraIssueReason::Woke],
    'active snooze is not a wake' => [fn () => ['is_important' => true, 'concerning_since' => now()->subDay(), 'snoozed_until' => now()->addHour()], JiraIssueReason::Starred],
    'mention newer than assignment' => [fn () => ['assigned_to_me_at' => now()->subDay(), 'last_mentioned_at' => now()->subHour()], JiraIssueReason::Mentioned],
    'unread comment' => [fn () => ['assigned_to_me_at' => now()->subDay(), 'last_commented_at' => now()->subHour(), 'last_viewed_at' => now()->subDays(2)], JiraIssueReason::Commented],
    'read comment' => [fn () => ['assigned_to_me_at' => now()->subDay(), 'last_commented_at' => now()->subHour(), 'last_viewed_at' => now()], JiraIssueReason::Assigned],
    'pinned without a star' => [fn () => ['concerning_since' => now()->subHour()], JiraIssueReason::Added],
    'assigned without a timestamp' => [fn () => ['assignee_account_id' => 'acc-me'], JiraIssueReason::Assigned],
    'description mention' => [fn () => ['mentions_me' => true], JiraIssueReason::Mentioned],
    'no reason' => [fn () => [], null],
]);
