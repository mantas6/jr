<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraClient;
use App\Services\Jira\JiraMentionDetector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/**
 * Scan the user's recently updated Jira issues for `@mentions` of their own
 * account in the description or comments, and record the result locally so the
 * "Concerning Tasks" list can surface them without hitting Jira per row.
 *
 * The time of the newest comment mention by someone else is kept in
 * `last_mentioned_at`, which re-surfaces a dismissed task and clears an active
 * snooze when it moves forward.
 */
#[UniqueFor(900)]
class SyncJiraMentionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The Jira issue fields required to detect mentions.
     *
     * @var list<string>
     */
    private const MENTION_FIELDS = ['description', 'comment'];

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job may run before timing out.
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(public User $user) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('mentions:'.$this->user->id))->expireAfter(600)];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $accountId = (string) $this->user->jira_account_id;

        if (!$this->user->hasJiraConnection() || $accountId === '') {
            return;
        }

        $now = Carbon::now();
        $client = JiraClient::forUser($this->user);
        $jql = sprintf('project = "%s" AND updated >= -14d ORDER BY updated DESC', $this->user->jira_project_key);

        $nextPageToken = null;

        do {
            $page = $client->searchIssues($jql, $nextPageToken, self::MENTION_FIELDS);

            /** @var \Illuminate\Support\Collection<string, JiraIssue> $existing */
            $existing = JiraIssue::query()
                ->where('user_id', $this->user->id)
                ->whereIn('jira_id', array_map(fn (array $issue): string => (string) data_get($issue, 'id'), $page['issues']))
                ->get(['id', 'jira_id', 'snoozed_until', 'last_mentioned_at'])
                ->keyBy('jira_id');

            foreach ($page['issues'] as $issue) {
                $current = $existing->get((string) data_get($issue, 'id'));

                if ($current !== null) {
                    $this->scanIssue($client, $current, $issue, $accountId, $now);
                }
            }

            $nextPageToken = $page['nextPageToken'] ?? null;
            $isLast = $page['isLast'] ?? ($nextPageToken === null);
        } while (!$isLast && $nextPageToken !== null);
    }

    /**
     * Detect a mention within a single issue payload and persist the result.
     *
     * `mentions_me` covers the description and every comment; only comment
     * mentions by someone else advance `last_mentioned_at`, which never moves
     * backward.
     *
     * @param  array<string, mixed>  $issue
     */
    private function scanIssue(JiraClient $client, JiraIssue $current, array $issue, string $accountId, Carbon $now): void
    {
        $comments = $this->comments($client, $issue);

        $attributes = [
            'mentions_me' => JiraMentionDetector::mentions(data_get($issue, 'fields.description'), $accountId)
                || JiraMentionDetector::mentions($comments, $accountId),
            'mentions_scanned_at' => $now,
        ];

        $mentionedAt = JiraMentionDetector::latestCommentMentionAt($comments, $accountId)?->startOfSecond();

        if ($mentionedAt !== null && ($current->last_mentioned_at === null || $mentionedAt->gt($current->last_mentioned_at))) {
            $attributes['last_mentioned_at'] = $mentionedAt;

            if ($current->isSnoozed()) {
                $attributes['snoozed_until'] = null;
            }
        }

        JiraIssue::query()
            ->whereKey($current->id)
            ->update($attributes);
    }

    /**
     * The issue's comments. Search results embed only the oldest comments, so
     * when Jira reports more than were returned the full list is fetched
     * (falling back to the embedded comments if that request fails).
     *
     * @param  array<string, mixed>  $issue
     * @return list<array<string, mixed>>
     */
    private function comments(JiraClient $client, array $issue): array
    {
        $comments = data_get($issue, 'fields.comment.comments');
        $comments = is_array($comments) ? array_values(array_filter($comments, is_array(...))) : [];

        $total = (int) data_get($issue, 'fields.comment.total', count($comments));

        if ($total <= count($comments)) {
            return $comments;
        }

        try {
            return $client->getAllComments((string) (data_get($issue, 'key') ?: data_get($issue, 'id')));
        } catch (JiraApiException) {
            return $comments;
        }
    }
}
