<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraClient;
use App\Services\Jira\JiraIssueMapper;
use App\Services\Jira\JiraTransitionsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pull every issue in the user's configured Jira project into the local
 * `jira_issues` table and warm the inline status-transition cache.
 */
#[UniqueFor(900)]
class SyncJiraIssuesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job may run before timing out.
     */
    public int $timeout = 300;

    /**
     * The columns updated when an existing issue row is matched during upsert.
     *
     * @var list<string>
     */
    private const UPSERT_COLUMNS = [
        'jira_key',
        'summary',
        'status',
        'status_id',
        'status_category',
        'issue_type',
        'priority',
        'assignee_account_id',
        'assignee_name',
        'reporter_name',
        'jira_url',
        'jira_created_at',
        'jira_updated_at',
        'raw',
        'last_synced_at',
    ];

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
        return [(new WithoutOverlapping((string) $this->user->id))->expireAfter(600)];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->user->hasJiraConnection()) {
            return;
        }

        $now = Carbon::now();
        $client = JiraClient::forUser($this->user);
        $jql = sprintf('project = "%s" ORDER BY updated DESC', $this->user->jira_project_key);

        /** @var array<string, string> $representatives Keyed by "issueType|statusId", value is a representative issue key. */
        $representatives = [];
        $nextPageToken = null;

        do {
            $page = $client->searchIssues($jql, $nextPageToken);

            $issues = $page['issues'];

            $rows = [];

            foreach ($issues as $issue) {
                $row = JiraIssueMapper::mapForUpsert($issue, $this->user, $now);
                $rows[] = $row;

                $pair = $row['issue_type'].'|'.$row['status_id'];
                $representatives[$pair] ??= (string) $row['jira_key'];
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                JiraIssue::upsert($chunk, ['user_id', 'jira_id'], self::UPSERT_COLUMNS);
            }

            $nextPageToken = $page['nextPageToken'] ?? null;
            $isLast = $page['isLast'] ?? ($nextPageToken === null);
        } while (! $isLast && $nextPageToken !== null);

        $this->warmTransitionsCache($client, $representatives);

        $this->user->forceFill([
            'jira_last_synced_at' => $now,
            'jira_last_sync_error' => null,
        ])->save();
    }

    /**
     * Warm the transitions cache for each distinct issue type + status pair synced.
     *
     * A failure for a single pair must not fail the whole sync.
     *
     * @param  array<string, string>  $representatives
     */
    private function warmTransitionsCache(JiraClient $client, array $representatives): void
    {
        foreach ($representatives as $pair => $issueKey) {
            [$issueType, $statusId] = explode('|', $pair, 2);

            try {
                $transitions = JiraTransitionsCache::fetch($client, $issueKey);
            } catch (JiraApiException) {
                continue;
            }

            JiraTransitionsCache::put($this->user, $issueType, $statusId, $transitions);
        }
    }

    /**
     * Record the failure message on the user when the job ultimately fails.
     */
    public function failed(?Throwable $exception): void
    {
        $this->user->forceFill([
            'jira_last_sync_error' => $exception?->getMessage(),
        ])->save();
    }
}
