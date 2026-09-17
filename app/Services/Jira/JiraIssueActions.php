<?php

declare(strict_types=1);

namespace App\Services\Jira;

use App\Models\JiraIssue;
use App\Models\User;
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
                $payload = $this->client->getIssue($key);
            } catch (JiraApiException $exception) {
                $failed[$key] = $exception->getMessage();

                continue;
            }

            $attributes = JiraIssueMapper::map($payload, $this->user, Carbon::now());
            $attributes['concerning_since'] = Carbon::now();

            JiraIssue::create($attributes);

            $added[] = $key;
        }

        return ['added' => $added, 'existing' => $existing, 'failed' => $failed];
    }

    /**
     * Re-fetch the issue from Jira and update the local row from the mapped payload.
     */
    private function refresh(JiraIssue $issue): JiraIssue
    {
        $payload = $this->client->getIssue($issue->jira_key);

        $attributes = JiraIssueMapper::map($payload, $this->user, Carbon::now());

        if (JiraIssue::shouldUnsnooze($issue->snoozed_until, $issue->jira_updated_at, $attributes['jira_updated_at'])) {
            $attributes['snoozed_until'] = null;
        }

        $issue->update($attributes);

        return $issue;
    }
}
