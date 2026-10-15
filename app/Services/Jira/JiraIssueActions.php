<?php

declare(strict_types=1);

namespace App\Services\Jira;

use App\Models\JiraIssue;
use App\Models\JiraRelease;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Performs synchronous write-backs to Jira (status transitions and assignee
 * changes) and refreshes the local row from the re-fetched payload. Keeps the
 * inline table column closures thin and gives tests a direct entry point.
 */
final class JiraIssueActions
{
    public function __construct(private User $user, private JiraClient $client) {}

    /**
     * Build the actions helper from the given user's stored credentials.
     */
    public static function forUser(User $user): self
    {
        return new self($user, JiraClient::forUser($user));
    }

    /**
     * Update the issue's pull request summary columns (`pr_state`, `pr_count`,
     * `pr_approved`) from its mapped pull request details, saving quietly
     * only when they changed. An empty list leaves the summary untouched.
     *
     * @param  list<array{status: string, approved: bool}>  $pullRequests  Pull requests mapped by {@see JiraIssueContentMapper::mapPullRequests()}.
     */
    public static function syncPullRequestSummary(JiraIssue $issue, array $pullRequests): void
    {
        if ($pullRequests === []) {
            return;
        }

        $issue->fill([
            'pr_state' => JiraPullRequestState::fromStatuses(array_column($pullRequests, 'status')),
            'pr_count' => count($pullRequests),
            'pr_approved' => JiraPullRequestState::hasApproval($pullRequests),
        ]);

        if ($issue->isDirty(['pr_state', 'pr_count', 'pr_approved'])) {
            $issue->saveQuietly();
        }
    }

    /**
     * Transition an issue to the target status, then refresh the local row.
     *
     * @throws JiraApiException When the target status is not reachable or Jira rejects the request.
     */
    public function transition(JiraIssue $issue, string $targetStatusId): JiraIssue
    {
        if ($targetStatusId === $issue->status_id) {
            return $issue;
        }

        $transitions = JiraTransitionsCache::remember(
            $this->user,
            $this->client,
            $issue->jira_key,
            $issue->issue_type,
            $issue->status_id,
        );

        $transition = collect($transitions)->firstWhere('to_id', $targetStatusId);

        if ($transition === null) {
            throw new JiraApiException('Transition not available');
        }

        $this->client->transitionIssue($issue->jira_key, $transition['id']);

        $issue = $this->refresh($issue);

        // Jira runs workflow post-functions asynchronously, so an immediate
        // re-fetch can still report the previous status. Trust the transition's
        // known destination so the UI does not revert to the old status.
        if ($issue->status_id !== $transition['to_id']) {
            $issue->forceFill([
                'status_id' => $transition['to_id'],
                'status' => $transition['to_name'],
            ])->save();
        }

        return $issue;
    }

    /**
     * Assign (or unassign, when `$accountId` is null) an issue, then refresh the local row.
     *
     * @throws JiraApiException When Jira rejects the request.
     */
    public function assign(JiraIssue $issue, ?string $accountId): JiraIssue
    {
        $this->client->assignIssue($issue->jira_key, $accountId);

        return $this->refresh($issue);
    }

    /**
     * Fetch the issue's linked pull requests from Jira and sync its pull
     * request summary columns from them (see {@see self::syncPullRequestSummary()}).
     *
     * @return list<array{title: string, url: string|null, status: string, repository: string, source: string, destination: string, author: string, reviewers: list<string>, approved: bool, updated: CarbonInterface|null}>
     *
     * @throws JiraApiException When Jira rejects the request.
     */
    public function pullRequests(JiraIssue $issue): array
    {
        $pullRequests = JiraIssueContentMapper::mapPullRequests($this->client->getPullRequests($issue->jira_id));

        self::syncPullRequestSummary($issue, $pullRequests);

        return $pullRequests;
    }

    /**
     * Add the given Jira keys to the user's concerning list.
     *
     * Existing rows are re-surfaced (their concerning pin is set, and any
     * dismissal/snooze is cleared) without hitting Jira. Unknown keys are fetched
     * from Jira, mapped, and created as pinned tasks. A per-key Jira failure is
     * recorded and does not abort the batch.
     *
     * @param  list<string>  $keys
     * @return array{added: list<string>, existing: list<string>, failed: array<string, string>}
     */
    public function addConcerning(array $keys): array
    {
        $added = [];
        $existing = [];
        $failed = [];

        foreach ($keys as $key) {
            $issue = JiraIssue::query()
                ->where('user_id', $this->user->id)
                ->where('jira_key', $key)
                ->first();

            if ($issue !== null) {
                $issue->update([
                    'concerning_since' => $issue->concerning_since ?? Carbon::now(),
                    'dismissed_at' => null,
                    'snoozed_until' => null,
                ]);

                $existing[] = $key;

                continue;
            }

            try {
                $payload = $this->client->getIssue($key, $this->extraFields());
            } catch (JiraApiException $exception) {
                $failed[$key] = $exception->getMessage();

                continue;
            }

            $attributes = JiraIssueMapper::map(
                $payload,
                $this->user,
                Carbon::now(),
                $this->client->sprintFieldId(),
                $this->client->developmentFieldId(),
            );
            $attributes['concerning_since'] = Carbon::now();

            $this->linkReleases(JiraIssue::create($attributes), $payload);

            $added[] = $key;
        }

        return ['added' => $added, 'existing' => $existing, 'failed' => $failed];
    }

    /**
     * Re-fetch the issue from Jira and update the local row from the mapped payload.
     *
     * When the refresh newly assigns the issue to the user, `assigned_to_me_at`
     * is stamped (re-surfacing a dismissed task) and any active snooze is cleared.
     * When it takes the issue away from the user, an unstarred task is dismissed.
     *
     * Sprint and pull request columns are left untouched when their custom
     * field id cannot be resolved, so a failed field lookup never wipes them.
     */
    private function refresh(JiraIssue $issue): JiraIssue
    {
        $sprintFieldId = $this->client->sprintFieldId();
        $developmentFieldId = $this->client->developmentFieldId();

        $payload = $this->client->getIssue($issue->jira_key, $this->extraFields());

        $attributes = JiraIssueMapper::map($payload, $this->user, Carbon::now(), $sprintFieldId, $developmentFieldId);

        if ($sprintFieldId === null) {
            unset($attributes['sprints'], $attributes['in_active_sprint']);
        }

        if ($developmentFieldId === null) {
            unset($attributes['pr_state'], $attributes['pr_count']);
        }

        if (JiraIssue::becameAssignedToMe($issue->assignee_account_id, $attributes['assignee_account_id'], $this->user)) {
            $attributes['assigned_to_me_at'] = Carbon::now();

            if ($issue->isSnoozed()) {
                $attributes['snoozed_until'] = null;
            }
        } elseif (!$issue->is_important && JiraIssue::becameUnassignedFromMe($issue->assignee_account_id, $attributes['assignee_account_id'], $this->user)) {
            $attributes = [...$attributes, ...JiraIssue::dismissalAttributes(Carbon::now())];
        }

        $issue->update($attributes);
        $this->linkReleases($issue, $payload);

        return $issue;
    }

    /**
     * Link the issue to the user's synced releases listed in the payload's
     * `fixVersions`, replacing its previous links.
     *
     * @param  array<string, mixed>  $payload
     */
    private function linkReleases(JiraIssue $issue, array $payload): void
    {
        $issue->releases()->sync(
            JiraRelease::query()
                ->where('user_id', $this->user->id)
                ->whereIn('jira_id', JiraIssueMapper::fixVersionIds($payload))
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * The resolved custom field ids (sprint, development) to request alongside
     * the standard issue fields.
     *
     * @return list<string>
     */
    private function extraFields(): array
    {
        return array_values(array_filter([
            $this->client->sprintFieldId(),
            $this->client->developmentFieldId(),
        ]));
    }
}
