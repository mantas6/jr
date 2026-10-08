<?php

use App\Models\JiraIssue;
use App\Models\User;
use Illuminate\Support\Carbon;

dataset('read states', [
    'never viewed' => [null, '2026-03-10 10:00:00', true],
    'updated in Jira since the last view' => ['2026-03-10 09:00:00', '2026-03-10 10:00:00', true],
    'viewed after the last Jira update' => ['2026-03-10 11:00:00', '2026-03-10 10:00:00', false],
    'viewed at the same moment as the last Jira update' => ['2026-03-10 10:00:00', '2026-03-10 10:00:00', false],
]);

test('isUnread reports whether the task changed in Jira since it was last viewed', function (?string $lastViewedAt, string $jiraUpdatedAt, bool $expected) {
    $issue = JiraIssue::factory()->make([
        'last_viewed_at' => $lastViewedAt === null ? null : Carbon::parse($lastViewedAt),
        'jira_updated_at' => Carbon::parse($jiraUpdatedAt),
    ]);

    expect($issue->isUnread())->toBe($expected);
})->with('read states');

test('the unread scope matches tasks changed in Jira since they were last viewed', function (?string $lastViewedAt, string $jiraUpdatedAt, bool $expected) {
    $issue = JiraIssue::factory()->for(User::factory())->create([
        'last_viewed_at' => $lastViewedAt === null ? null : Carbon::parse($lastViewedAt),
        'jira_updated_at' => Carbon::parse($jiraUpdatedAt),
    ]);

    expect(JiraIssue::query()->unread()->whereKey($issue->id)->exists())->toBe($expected);
})->with('read states');
