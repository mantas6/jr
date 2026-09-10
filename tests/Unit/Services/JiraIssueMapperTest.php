<?php

use App\Models\User;
use App\Services\Jira\JiraIssueMapper;
use Illuminate\Support\Carbon;

function fullJiraPayload(): array
{
    return [
        'id' => '10001',
        'key' => 'PROJ-42',
        'fields' => [
            'summary' => 'Fix the login bug',
            'status' => [
                'name' => 'In Progress',
                'id' => '3',
                'statusCategory' => ['name' => 'In Progress'],
            ],
            'issuetype' => ['name' => 'Bug'],
            'priority' => ['name' => 'High'],
            'assignee' => ['accountId' => 'acc-1', 'displayName' => 'Jane Doe'],
            'reporter' => ['displayName' => 'John Smith'],
            'created' => '2024-01-02T10:00:00.000+0000',
            'updated' => '2024-02-03T12:30:00.000+0000',
        ],
    ];
}

test('map converts a full payload into all columns', function () {
    $user = User::factory()->make([
        'id' => 7,
        'jira_site_url' => 'https://example.atlassian.net',
    ]);
    $user->id = 7;

    $syncedAt = Carbon::parse('2024-05-01 09:00:00');

    $row = JiraIssueMapper::map(fullJiraPayload(), $user, $syncedAt);

    expect($row['user_id'])->toBe(7)
        ->and($row['jira_id'])->toBe('10001')
        ->and($row['jira_key'])->toBe('PROJ-42')
        ->and($row['summary'])->toBe('Fix the login bug')
        ->and($row['status'])->toBe('In Progress')
        ->and($row['status_id'])->toBe('3')
        ->and($row['status_category'])->toBe('In Progress')
        ->and($row['issue_type'])->toBe('Bug')
        ->and($row['priority'])->toBe('High')
        ->and($row['assignee_account_id'])->toBe('acc-1')
        ->and($row['assignee_name'])->toBe('Jane Doe')
        ->and($row['reporter_name'])->toBe('John Smith')
        ->and($row['jira_url'])->toBe('https://example.atlassian.net/browse/PROJ-42')
        ->and($row['jira_created_at']->toDateTimeString())->toBe('2024-01-02 10:00:00')
        ->and($row['jira_updated_at']->toDateTimeString())->toBe('2024-02-03 12:30:00')
        ->and($row['raw'])->toBe(fullJiraPayload()['fields'])
        ->and($row['last_synced_at']->toDateTimeString())->toBe('2024-05-01 09:00:00');
});

test('map handles null assignee, priority and reporter gracefully', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net/']);
    $user->id = 3;

    $payload = fullJiraPayload();
    unset($payload['fields']['assignee'], $payload['fields']['priority'], $payload['fields']['reporter']);

    $row = JiraIssueMapper::map($payload, $user);

    expect($row['priority'])->toBeNull()
        ->and($row['assignee_account_id'])->toBeNull()
        ->and($row['assignee_name'])->toBeNull()
        ->and($row['reporter_name'])->toBeNull();
});

test('map builds the jira_url correctly when the site url has a trailing slash', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net/']);
    $user->id = 1;

    $row = JiraIssueMapper::map(fullJiraPayload(), $user);

    expect($row['jira_url'])->toBe('https://example.atlassian.net/browse/PROJ-42');
});

test('mapForUpsert json-encodes raw and formats timestamps as strings', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 5;

    $row = JiraIssueMapper::mapForUpsert(fullJiraPayload(), $user, Carbon::parse('2024-05-01 09:00:00'));

    expect($row['raw'])->toBeString()
        ->and(json_decode($row['raw'], true))->toBe(fullJiraPayload()['fields'])
        ->and($row['jira_created_at'])->toBe('2024-01-02 10:00:00')
        ->and($row['jira_updated_at'])->toBe('2024-02-03 12:30:00')
        ->and($row['last_synced_at'])->toBe('2024-05-01 09:00:00');
});
