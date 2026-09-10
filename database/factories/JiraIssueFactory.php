<?php

namespace Database\Factories;

use App\Models\JiraIssue;
use App\Models\User;
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
        $statusCategory = fake()->randomElement(['To Do', 'In Progress', 'Done']);
        $status = match ($statusCategory) {
            'To Do' => 'To Do',
            'In Progress' => 'In Progress',
            default => 'Done',
        };

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
            'jira_url' => $siteUrl.'/browse/'.$jiraKey,
            'jira_created_at' => fake()->dateTimeBetween('-1 year', '-1 month'),
            'jira_updated_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'raw' => ['fields' => []],
            'last_synced_at' => now(),
        ];
    }
}
