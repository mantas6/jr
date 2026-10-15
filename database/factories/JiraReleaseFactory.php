<?php

namespace Database\Factories;

use App\Models\JiraRelease;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JiraRelease>
 */
class JiraReleaseFactory extends Factory
{
    /**
     * Define the model's default state: an unreleased, unarchived version.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $jiraId = (string) fake()->unique()->numberBetween(10000, 99999);

        return [
            'user_id' => User::factory(),
            'jira_id' => $jiraId,
            'name' => 'v'.fake()->numberBetween(1, 9).'.'.fake()->numberBetween(0, 20).'.'.fake()->numberBetween(0, 9),
            'description' => fake()->optional()->sentence(),
            'released' => false,
            'archived' => false,
            'overdue' => false,
            'start_date' => fake()->optional()->dateTimeBetween('-2 months', '-1 month'),
            'release_date' => fake()->optional()->dateTimeBetween('now', '+2 months'),
            'jira_url' => 'https://example.atlassian.net/projects/PROJ/versions/'.$jiraId,
            'raw' => ['id' => $jiraId],
            'last_synced_at' => now(),
        ];
    }

    /**
     * Mark the release as released (and not archived).
     */
    public function released(): static
    {
        return $this->state(fn (array $attributes): array => [
            'released' => true,
            'release_date' => $attributes['release_date'] ?? now()->subWeek(),
        ]);
    }

    /**
     * Mark the release as archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived' => true,
        ]);
    }

    /**
     * Mark the unreleased release as past its release date.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'overdue' => true,
            'release_date' => now()->subWeek(),
        ]);
    }
}
