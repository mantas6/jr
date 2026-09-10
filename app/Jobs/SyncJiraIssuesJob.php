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
     *
     * Allows a few overlap-release retries when a forced run queues behind a
     * scheduled sync that is still in flight.
     */
    public int $tries = 5;

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
        'sprints',
        'jira_url',
        'jira_created_at',
        'jira_updated_at',
        'raw',
        'last_synced_at',
    ];

    /**
     * Create a new job instance.
     *
     * A forced run bypasses the incremental window and always performs a full
     * project sync (manual "Full resync" button / `jira:sync --force`).
     */
    public function __construct(public User $user, public bool $force = false) {}

    /**
     * Get the unique ID for the job.
     *
     * A forced run uses a distinct key so a manual full resync is not deduped
     * by an already-queued scheduled (incremental) run.
     */
    public function uniqueId(): string
    {
        return $this->force
            ? $this->user->id.':force'
            : (string) $this->user->id;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * A forced run queues behind an in-flight sync by releasing back onto the
     * queue rather than being dropped.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->user->id))
                ->releaseAfter(15)
                ->expireAfter(600),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->user->hasJiraConnection()) {
            return;
        }

        try {
            $this->sync();
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Pull issues from Jira into the local table, warm the transitions cache,
     * and stamp the sync timestamps.
     */
    private function sync(): void
    {
        $now = Carbon::now();
        $isFullSync = $this->isFullSync($now);

        $client = JiraClient::forUser($this->user);
        $jql = $this->buildJql($isFullSync, $now);

        $sprintFieldId = $client->sprintFieldId();
        $extraFields = $sprintFieldId !== null ? [$sprintFieldId] : [];

        /** @var array<string, string> $representatives Keyed by "issueType|statusId", value is a representative issue key. */
        $representatives = [];
        $nextPageToken = null;

        do {
            $page = $client->searchIssues($jql, $nextPageToken, $extraFields);

            $issues = $page['issues'];

            $rows = [];

            foreach ($issues as $issue) {
                $row = JiraIssueMapper::mapForUpsert($issue, $this->user, $now, $sprintFieldId);
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

        $attributes = [
            'jira_last_synced_at' => $now,
            'jira_last_sync_error' => null,
        ];

        if ($isFullSync) {
            $attributes['jira_last_full_synced_at'] = $now;
        }

        $this->user->forceFill($attributes)->save();

        SyncJiraMentionsJob::dispatch($this->user);
    }

    /**
     * Determine whether this run should pull the entire project rather than the
     * incremental window. Self-promotes to a full sync once every 24 hours so a
     * separate nightly cron is unnecessary.
     */
    private function isFullSync(Carbon $now): bool
    {
        if ($this->force) {
            return true;
        }

        $lastFullSyncedAt = $this->user->jira_last_full_synced_at;

        return $lastFullSyncedAt === null
            || $lastFullSyncedAt->lt($now->copy()->subDay());
    }

    /**
     * Build the search JQL. A full sync pulls the whole project; an incremental
     * sync only pulls issues updated within a relative window (avoiding timezone
     * pitfalls) sized to the time since the last sync plus an overlap buffer.
     */
    private function buildJql(bool $isFullSync, Carbon $now): string
    {
        $project = (string) $this->user->jira_project_key;

        if ($isFullSync) {
            return sprintf('project = "%s" ORDER BY updated DESC', $project);
        }

        return sprintf(
            'project = "%s" AND updated >= -%dm ORDER BY updated DESC',
            $project,
            $this->incrementalWindowMinutes($now),
        );
    }

    /**
     * Minutes since the last sync plus a 5 minute overlap buffer for clock skew
     * and mid-sync edits.
     */
    private function incrementalWindowMinutes(Carbon $now): int
    {
        $lastSyncedAt = $this->user->jira_last_synced_at;

        $elapsed = $lastSyncedAt !== null
            ? (int) ceil($lastSyncedAt->diffInMinutes($now))
            : 0;

        return $elapsed + 5;
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
