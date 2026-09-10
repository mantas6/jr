<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user has a fully configured Jira connection.
     */
    public function withJiraConnection(): static
    {
        return $this->state(fn (array $attributes) => [
            'jira_site_url' => 'https://example.atlassian.net',
            'jira_email' => fake()->unique()->safeEmail(),
            'jira_api_token' => 'test-api-token',
            'jira_account_id' => fake()->uuid(),
            'jira_project_key' => 'PROJ',
            'jira_connected_at' => now(),
        ]);
    }
}
