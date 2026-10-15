<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\JiraIssue;
use App\Models\JiraRelease;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraClient;
use App\Services\Jira\JiraIssueContentMapper;
use App\Services\Jira\JiraIssueMapper;
use App\Services\Jira\JiraPullRequestState;
use App\Services\Jira\JiraReleaseMapper;
use App\Services\Jira\JiraTransitionsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pull every version (release) and issue in the user's configured Jira project
 * into the local `jira_releases` and `jira_issues` tables, link issues to their
 * fix versions, and warm the inline status-transition cache.
 */
#[UniqueFor(900)]
class SyncJiraIssuesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

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
        'in_active_sprint',
        'original_estimate_seconds',
        'pr_state',
        'pr_count',
        'jira_url',
        'jira_created_at',
        'jira_updated_at',
        'raw',
        'last_synced_at',
    ];

    /**
     * The columns updated when an existing release row is matched during upsert.
     *
     * @var list<string>
     */
    private const RELEASE_UPSERT_COLUMNS = [
        'name',
        'description',
        'released',
        'archived',
        'overdue',
        'start_date',
        'release_date',
        'jira_url',
        'raw',
        'last_synced_at',
    ];

    /**
     * The pivot table linking issues to the releases in their `fixVersions`.
     */
    private const RELEASE_PIVOT_TABLE = 'jira_issue_jira_release';

    /**
     * The number of seconds the job may run before timing out.
     */
    public int $timeout = 300;

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
     * A non-forced run is dropped while another sync for the user is in
     * flight, since the next scheduled run picks up anything it misses. A
     * forced run queues behind it by releasing back onto the queue. The lock
     * outlives the job timeout only briefly, so a worker killed mid-sync does
     * not block syncs for long.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $middleware = (new WithoutOverlapping((string) $this->user->id))
            ->expireAfter($this->timeout + 30);

        return [
            $this->force ? $middleware->releaseAfter(15) : $middleware->dontRelease(),
        ];
    }

    /**
     * Determine the time at which the job should stop being retried.
     *
     * Used instead of a fixed number of tries so a forced run released behind
     * an in-flight or abandoned overlap lock keeps waiting until it is gone.
     */
    public function retryUntil(): Carbon
    {
        return Carbon::now()->addMinutes(10);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->user->hasJiraConnection()) {
            return;
        }

        try {
            $this->sync();
        } catch (Throwable $exception) {
            $this->fail($exception);
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

    /**
     * Pull releases and issues from Jira into the local tables, warm the
     * transitions cache, and stamp the sync timestamps.
     */
    private function sync(): void
    {
        $now = Carbon::now();
        $isFullSync = $this->isFullSync($now);

        $client = JiraClient::forUser($this->user);
        $jql = $this->buildJql($isFullSync, $now);

        $releaseIds = $this->syncReleases($client, $now);

        $sprintFieldId = $client->sprintFieldId();
        $developmentFieldId = $client->developmentFieldId();
        $extraFields = array_values(array_filter([$sprintFieldId, $developmentFieldId]));

        /** @var array<string, string> $representatives Keyed by "issueType|statusId", value is a representative issue key. */
        $representatives = [];
        $nextPageToken = null;

        do {
            $page = $client->searchIssues($jql, $nextPageToken, $extraFields);

            $issues = $page['issues'];

            $refreshIds = [];

            foreach ($issues as $issue) {
                if ($developmentFieldId !== null && JiraIssueMapper::needsPullRequestRefresh(data_get($issue, 'fields.'.$developmentFieldId))) {
                    $refreshIds[] = (string) $issue['id'];
                }
            }

            $developmentSummaries = $client->getDevelopmentSummaries($refreshIds);

            $rows = [];

            /** @var array<string, list<string>> $fixVersions Keyed by issue jira_id. */
            $fixVersions = [];

            foreach ($issues as $issue) {
                $row = JiraIssueMapper::mapForUpsert($issue, $this->user, $now, $sprintFieldId, $developmentFieldId);
                $fixVersions[(string) $row['jira_id']] = JiraIssueMapper::fixVersionIds($issue);

                if (array_key_exists((string) $row['jira_id'], $developmentSummaries)) {
                    $pullRequests = JiraIssueMapper::extractPullRequestSummary($developmentSummaries[$row['jira_id']]);
                    $row['pr_state'] = $pullRequests['state']?->value;
                    $row['pr_count'] = $pullRequests['count'];
                }

                $rows[] = $row;

                $pair = $row['issue_type'].'|'.$row['status_id'];
                $representatives[$pair] ??= (string) $row['jira_key'];
            }

            foreach (array_chunk($rows, 100) as $chunk) {
                $this->upsertChunk($chunk, $now);
            }

            $this->syncReleaseLinks($fixVersions, $releaseIds);

            $nextPageToken = $page['nextPageToken'] ?? null;
            $isLast = $page['isLast'] ?? ($nextPageToken === null);
        } while (!$isLast && $nextPageToken !== null);

        $this->refreshPullRequestApprovals($client);
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
     * Upsert every version of the user's project and delete local releases
     * that no longer exist in Jira (their issue links cascade away).
     *
     * A failed lookup must not fail the whole sync: the previously synced
     * releases are kept and issues are still linked against them.
     *
     * @return array<string, int> Local release ids keyed by Jira version id.
     */
    private function syncReleases(JiraClient $client, Carbon $now): array
    {
        try {
            $versions = $client->getProjectVersions((string) $this->user->jira_project_key);
        } catch (JiraApiException) {
            return $this->releaseIds();
        }

        $rows = [];

        foreach ($versions as $version) {
            $row = JiraReleaseMapper::mapForUpsert($version, $this->user, $now);

            if ($row['jira_id'] !== '') {
                $rows[] = $row;
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            JiraRelease::upsert($chunk, ['user_id', 'jira_id'], self::RELEASE_UPSERT_COLUMNS);
        }

        JiraRelease::query()
            ->where('user_id', $this->user->id)
            ->whereNotIn('jira_id', array_column($rows, 'jira_id'))
            ->delete();

        return $this->releaseIds();
    }

    /**
     * The user's local release ids keyed by Jira version id.
     *
     * @return array<string, int>
     */
    private function releaseIds(): array
    {
        $releaseIds = [];

        foreach (JiraRelease::query()->where('user_id', $this->user->id)->get(['id', 'jira_id']) as $release) {
            $releaseIds[$release->jira_id] = $release->id;
        }

        return $releaseIds;
    }

    /**
     * Replace the release links of the given (just upserted) issues with the
     * releases in their `fixVersions`. Versions not synced locally are skipped;
     * the next sync of the issue links them.
     *
     * @param  array<string, list<string>>  $fixVersions  Jira version ids keyed by issue jira_id.
     * @param  array<string, int>  $releaseIds  Local release ids keyed by Jira version id.
     */
    private function syncReleaseLinks(array $fixVersions, array $releaseIds): void
    {
        if ($fixVersions === []) {
            return;
        }

        $issueIds = JiraIssue::query()
            ->where('user_id', $this->user->id)
            ->whereIn('jira_id', array_map(strval(...), array_keys($fixVersions)))
            ->pluck('id', 'jira_id');

        $links = [];

        foreach ($fixVersions as $jiraId => $versionIds) {
            $issueId = $issueIds->get((string) $jiraId);

            if ($issueId === null) {
                continue;
            }

            foreach ($versionIds as $versionId) {
                if (isset($releaseIds[$versionId])) {
                    $links[] = ['jira_issue_id' => $issueId, 'jira_release_id' => $releaseIds[$versionId]];
                }
            }
        }

        DB::transaction(function () use ($issueIds, $links): void {
            DB::table(self::RELEASE_PIVOT_TABLE)
                ->whereIn('jira_issue_id', $issueIds->values()->all())
                ->delete();

            foreach (array_chunk($links, 500) as $chunk) {
                DB::table(self::RELEASE_PIVOT_TABLE)->insert($chunk);
            }
        });
    }

    /**
     * Upsert a chunk of mapped rows, then reconcile assignee changes on existing
     * rows: those newly assigned to the user get `assigned_to_me_at` stamped and
     * any active snooze cleared, and unstarred ones reassigned away from the
     * user are dismissed. New rows need neither since they have never been
     * dismissed.
     *
     * @param  list<array<string, string|int|null>>  $chunk
     */
    private function upsertChunk(array $chunk, Carbon $now): void
    {
        $jiraIds = array_column($chunk, 'jira_id');

        /** @var \Illuminate\Support\Collection<string, JiraIssue> $existing */
        $existing = JiraIssue::query()
            ->where('user_id', $this->user->id)
            ->whereIn('jira_id', $jiraIds)
            ->get(['jira_id', 'assignee_account_id', 'snoozed_until', 'is_important'])
            ->keyBy('jira_id');

        JiraIssue::upsert($chunk, ['user_id', 'jira_id'], self::UPSERT_COLUMNS);

        $assignedIds = [];
        $unsnoozeIds = [];
        $dismissIds = [];

        foreach ($chunk as $row) {
            $current = $existing->get((string) $row['jira_id']);

            if ($current === null) {
                continue;
            }

            $incomingAssignee = $row['assignee_account_id'] === null ? null : (string) $row['assignee_account_id'];

            if (JiraIssue::becameUnassignedFromMe($current->assignee_account_id, $incomingAssignee, $this->user)) {
                if (!$current->is_important) {
                    $dismissIds[] = $row['jira_id'];
                }

                continue;
            }

            if (!JiraIssue::becameAssignedToMe($current->assignee_account_id, $incomingAssignee, $this->user)) {
                continue;
            }

            $assignedIds[] = $row['jira_id'];

            if ($current->isSnoozed()) {
                $unsnoozeIds[] = $row['jira_id'];
            }
        }

        if ($assignedIds !== []) {
            JiraIssue::query()
                ->where('user_id', $this->user->id)
                ->whereIn('jira_id', $assignedIds)
                ->update(['assigned_to_me_at' => $now]);
        }

        if ($unsnoozeIds !== []) {
            JiraIssue::query()
                ->where('user_id', $this->user->id)
                ->whereIn('jira_id', $unsnoozeIds)
                ->update(['snoozed_until' => null]);
        }

        if ($dismissIds !== []) {
            JiraIssue::query()
                ->where('user_id', $this->user->id)
                ->whereIn('jira_id', $dismissIds)
                ->update(JiraIssue::dismissalAttributes($now));
        }
    }

    /**
     * Refresh whether each task with an open or draft pull request has an
     * approval. Jira only reports approvals in pull request details, and an
     * approval does not bump the issue's updated time, so every such task is
     * checked on each sync. A failed lookup keeps the previous value.
     */
    private function refreshPullRequestApprovals(JiraClient $client): void
    {
        $approvals = JiraIssue::query()
            ->where('user_id', $this->user->id)
            ->whereIn('pr_state', [JiraPullRequestState::Open->value, JiraPullRequestState::Draft->value])
            ->pluck('pr_approved', 'jira_id');

        /** @var array{approved: list<string>, unapproved: list<string>} $changes */
        $changes = ['approved' => [], 'unapproved' => []];

        foreach ($client->getPullRequestsForIssues(array_values($approvals->keys()->map(strval(...))->all())) as $issueId => $pullRequests) {
            if ($pullRequests === null) {
                continue;
            }

            $approved = JiraPullRequestState::hasApproval(JiraIssueContentMapper::mapPullRequests($pullRequests));

            if ($approved !== (bool) $approvals->get($issueId)) {
                $changes[$approved ? 'approved' : 'unapproved'][] = (string) $issueId;
            }
        }

        foreach ($changes as $change => $issueIds) {
            if ($issueIds !== []) {
                JiraIssue::query()
                    ->where('user_id', $this->user->id)
                    ->whereIn('jira_id', $issueIds)
                    ->update(['pr_approved' => $change === 'approved']);
            }
        }
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
}
