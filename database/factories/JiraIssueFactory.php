<?php

namespace Database\Factories;

use App\Models\JiraIssue;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JiraIssue>
 */
class JiraIssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Default to an open status category so records stay visible under the
        // full task list's default-on "Not closed" filter. Use the done() state
        // for Done.
        $statusCategory = fake()->randomElement(['To Do', 'In Progress']);
        $status = $statusCategory;

        $projectKey = 'PROJ';
        $jiraKey = $projectKey.'-'.fake()->numberBetween(1, 999);
        $jiraId = (string) fake()->unique()->numberBetween(10000, 99999);
        $siteUrl = 'https://example.atlassian.net';

        return [
            'user_id' => User::factory(),
            'jira_id' => $jiraId,
            'jira_key' => $jiraKey,
            'summary' => fake()->sentence(),
            'status' => $status,
            'status_id' => (string) fake()->numberBetween(1, 10),
            'status_category' => $statusCategory,
            'issue_type' => fake()->randomElement(['Task', 'Bug', 'Story', 'Epic']),
            'priority' => fake()->randomElement(['Highest', 'High', 'Medium', 'Low', 'Lowest']),
            'assignee_account_id' => fake()->optional()->uuid(),
            'assignee_name' => fake()->optional()->name(),
            'reporter_name' => fake()->name(),
            'sprints' => null,
            'in_active_sprint' => false,
            'is_important' => false,
            'concerning_since' => null,
            'snoozed_until' => null,
            'dismissed_at' => null,
            'mentions_me' => false,
            'mentions_scanned_at' => null,
            'jira_url' => $siteUrl.'/browse/'.$jiraKey,
            'jira_created_at' => fake()->dateTimeBetween('-1 year', '-1 month'),
            'jira_updated_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'raw' => ['fields' => []],
            'last_synced_at' => now(),
        ];
    }

    /**
     * Mark the issue as closed (Done status category).
     */
    public function done(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'Done',
            'status_category' => 'Done',
        ]);
    }

    /**
     * Mark the issue as belonging to the current (active) sprint.
     */
    public function inActiveSprint(): static
    {
        return $this->state(fn (array $attributes): array => [
            'in_active_sprint' => true,
            'sprints' => $attributes['sprints'] ?? ['Current Sprint'],
        ]);
    }

    /**
     * Mark the issue as important (starred), pinning it to the concerning list.
     */
    public function important(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_important' => true,
            'concerning_since' => now(),
        ]);
    }

    /**
     * Pin the issue to the concerning list without starring it, mirroring a task
     * that was starred and later unstarred.
     */
    public function concerning(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_important' => false,
            'concerning_since' => now(),
        ]);
    }

    /**
     * Snooze the issue until the given time (defaults to one hour from now).
     */
    public function snoozed(?DateTimeInterface $until = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'snoozed_until' => $until ?? now()->addHour(),
        ]);
    }

    /**
     * Give the issue a snooze that has already expired (defaults to one hour ago),
     * so it counts as no longer snoozed.
     */
    public function expiredSnooze(?DateTimeInterface $at = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'snoozed_until' => $at ?? now()->subHour(),
        ]);
    }

    /**
     * Mark the issue as dismissed at the given time (defaults to now).
     */
    public function dismissed(?DateTimeInterface $at = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'dismissed_at' => $at ?? now(),
        ]);
    }

    /**
     * Mark the issue as mentioning the current user.
     */
    public function mentionsMe(): static
    {
        return $this->state(fn (array $attributes): array => [
            'mentions_me' => true,
            'mentions_scanned_at' => now(),
        ]);
    }
}
