<?php

use App\Models\JiraIssue;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('hasJiraConnection returns true when all fields are present', function () {
    $user = User::factory()->withJiraConnection()->create();

    expect($user->hasJiraConnection())->toBeTrue();
});

test('hasJiraConnection returns false when connection fields are missing', function () {
    $user = User::factory()->create();

    expect($user->hasJiraConnection())->toBeFalse();
});

test('hasJiraConnection returns false when only some fields are present', function () {
    $user = User::factory()->create([
        'jira_site_url' => 'https://example.atlassian.net',
        'jira_email' => 'me@example.com',
    ]);

    expect($user->hasJiraConnection())->toBeFalse();
});

test('jira_api_token is encrypted at rest', function () {
    $user = User::factory()->create([
        'jira_api_token' => 'super-secret-token',
    ]);

    $rawValue = DB::table('users')->where('id', $user->id)->value('jira_api_token');

    expect($rawValue)->not->toBe('super-secret-token');
    expect($user->fresh()->jira_api_token)->toBe('super-secret-token');
});

test('jiraIssues relation returns the user issues', function () {
    $user = User::factory()->create();
    JiraIssue::factory()->count(3)->for($user)->create();
    JiraIssue::factory()->count(2)->create();

    expect($user->jiraIssues)->toHaveCount(3);
    expect($user->jiraIssues->first())->toBeInstanceOf(JiraIssue::class);
});

test('unique constraint on user_id and jira_id throws on duplicate', function () {
    $user = User::factory()->create();

    JiraIssue::factory()->for($user)->create(['jira_id' => '12345']);

    JiraIssue::factory()->for($user)->create(['jira_id' => '12345']);
})->throws(QueryException::class);
