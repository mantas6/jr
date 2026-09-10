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
    public function __construct(
        private User $user,
        private JiraClient $client,
    ) {}

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

        return $this->refresh($issue);
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
     * Re-fetch the issue from Jira and update the local row from the mapped payload.
     */
    private function refresh(JiraIssue $issue): JiraIssue
    {
        $payload = $this->client->getIssue($issue->jira_key);

        $issue->update(JiraIssueMapper::map($payload, $this->user, Carbon::now()));

        return $issue;
    }
}
