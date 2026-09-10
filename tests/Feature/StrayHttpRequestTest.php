<?php

use App\Models\User;
use App\Services\Jira\JiraClient;
use Illuminate\Http\Client\StrayRequestException;

test('an un-faked jira request is prevented instead of hitting the real api', function () {
    $user = User::factory()->withJiraConnection()->create();

    // No Http::fake() here: the global preventStrayRequests() guard in
    // tests/Pest.php must stop this from making a real request to Jira.
    expect(fn () => JiraClient::forUser($user)->me())
        ->toThrow(StrayRequestException::class);
});
