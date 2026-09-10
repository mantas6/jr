<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\JiraIssue;
use App\Models\User;
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

            foreach ($page['issues'] as $issue) {
                $this->scanIssue($issue, $accountId, $now);
            }

            $nextPageToken = $page['nextPageToken'] ?? null;
            $isLast = $page['isLast'] ?? ($nextPageToken === null);
        } while (!$isLast && $nextPageToken !== null);
    }

    /**
     * Detect a mention within a single issue payload and persist the result.
     *
     * @param  array<string, mixed>  $issue
     */
    private function scanIssue(array $issue, string $accountId, Carbon $now): void
    {
        $jiraId = (string) data_get($issue, 'id');

        if ($jiraId === '') {
            return;
        }

        $mentionsMe = JiraMentionDetector::mentions(data_get($issue, 'fields.description'), $accountId)
            || JiraMentionDetector::mentions(data_get($issue, 'fields.comment.comments'), $accountId);

        JiraIssue::query()
            ->where('user_id', $this->user->id)
            ->where('jira_id', $jiraId)
            ->update([
                'mentions_me' => $mentionsMe,
                'mentions_scanned_at' => $now,
            ]);
    }
}
