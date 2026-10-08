<?php

use App\Models\User;
use App\Services\Jira\JiraIssueMapper;
use App\Services\Jira\JiraPullRequestState;
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
            'timeoriginalestimate' => 9000,
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
        ->and($row['original_estimate_seconds'])->toBe(9000)
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

test('map returns a null original estimate when jira has none', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $payload = fullJiraPayload();
    $payload['fields']['timeoriginalestimate'] = null;

    expect(JiraIssueMapper::map($payload, $user)['original_estimate_seconds'])->toBeNull();
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

test('parseDate normalizes non-UTC Jira timestamps to the app timezone', function () {
    config()->set('app.timezone', 'UTC');

    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 5;

    $payload = fullJiraPayload();
    $payload['fields']['created'] = '2026-09-10T10:00:00.000+0300';
    $payload['fields']['updated'] = '2026-09-10T12:14:21.653+0300';

    $row = JiraIssueMapper::mapForUpsert($payload, $user, Carbon::parse('2026-09-10 09:30:00'));

    expect($row['jira_created_at'])->toBe('2026-09-10 07:00:00')
        ->and($row['jira_updated_at'])->toBe('2026-09-10 09:14:21');
});

test('sprints is null when no sprint field id is given', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $payload = fullJiraPayload();
    $payload['fields']['customfield_10020'] = [
        ['name' => 'Sprint 1', 'state' => 'closed'],
    ];

    $row = JiraIssueMapper::map($payload, $user);

    expect($row['sprints'])->toBeNull();
});

test('map extracts every sprint name from the sprint custom field', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $payload = fullJiraPayload();
    $payload['fields']['customfield_10020'] = [
        ['name' => 'Sprint 1', 'state' => 'closed'],
        ['name' => 'Sprint 2', 'state' => 'active'],
    ];

    $row = JiraIssueMapper::map($payload, $user, null, 'customfield_10020');

    expect($row['sprints'])->toBe(['Sprint 1', 'Sprint 2']);
});

test('map returns null sprints when the sprint field is empty or absent', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $row = JiraIssueMapper::map(fullJiraPayload(), $user, null, 'customfield_10020');

    expect($row['sprints'])->toBeNull();
});

test('map parses the legacy sprint string format', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $payload = fullJiraPayload();
    $payload['fields']['customfield_10020'] = [
        'com.atlassian.greenhopper.service.sprint.Sprint@1[id=1,name=Legacy Sprint,state=CLOSED]',
    ];

    $row = JiraIssueMapper::map($payload, $user, null, 'customfield_10020');

    expect($row['sprints'])->toBe(['Legacy Sprint']);
});

test('in_active_sprint is true when a modern sprint is active', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $payload = fullJiraPayload();
    $payload['fields']['customfield_10020'] = [
        ['name' => 'Sprint 1', 'state' => 'closed'],
        ['name' => 'Sprint 2', 'state' => 'active'],
    ];

    $row = JiraIssueMapper::map($payload, $user, null, 'customfield_10020');

    expect($row['in_active_sprint'])->toBeTrue();
});

test('in_active_sprint is true when a legacy sprint string has state=ACTIVE', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $payload = fullJiraPayload();
    $payload['fields']['customfield_10020'] = [
        'com.atlassian.greenhopper.service.sprint.Sprint@1[id=1,name=Legacy Sprint,state=ACTIVE]',
    ];

    $row = JiraIssueMapper::map($payload, $user, null, 'customfield_10020');

    expect($row['in_active_sprint'])->toBeTrue();
});

test('in_active_sprint is false when only closed or future sprints exist', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $payload = fullJiraPayload();
    $payload['fields']['customfield_10020'] = [
        ['name' => 'Sprint 1', 'state' => 'closed'],
        ['name' => 'Sprint 2', 'state' => 'future'],
    ];

    $row = JiraIssueMapper::map($payload, $user, null, 'customfield_10020');

    expect($row['in_active_sprint'])->toBeFalse();
});

test('in_active_sprint is null when the sprint field is absent', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $withoutFieldId = JiraIssueMapper::map(fullJiraPayload(), $user);
    $withFieldIdButNoSprint = JiraIssueMapper::map(fullJiraPayload(), $user, null, 'customfield_10020');

    expect($withoutFieldId['in_active_sprint'])->toBeNull()
        ->and($withFieldIdButNoSprint['in_active_sprint'])->toBeNull();
});

test('mapForUpsert casts in_active_sprint to an integer', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 5;

    $payload = fullJiraPayload();
    $payload['fields']['customfield_10020'] = [
        ['name' => 'Sprint 1', 'state' => 'active'],
    ];

    $row = JiraIssueMapper::mapForUpsert($payload, $user, Carbon::parse('2024-05-01 09:00:00'), 'customfield_10020');

    expect($row['in_active_sprint'])->toBe(1);
});

test('mapForUpsert json-encodes the sprints array', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 5;

    $payload = fullJiraPayload();
    $payload['fields']['customfield_10020'] = [
        ['name' => 'Sprint 1'],
    ];

    $row = JiraIssueMapper::mapForUpsert($payload, $user, Carbon::parse('2024-05-01 09:00:00'), 'customfield_10020');

    expect($row['sprints'])->toBe(json_encode(['Sprint 1']));
});

/**
 * Build a Jira payload whose Development summary field (`customfield_10000`)
 * holds the given raw value.
 */
function developmentPayload(mixed $value): array
{
    $payload = fullJiraPayload();
    $payload['fields']['customfield_10000'] = $value;

    return $payload;
}

/**
 * Encode a current-format Development summary field value.
 */
function developmentSummary(int $count, string $state): string
{
    return json_encode([
        'cachedValue' => [
            'errors' => [],
            'summary' => [
                'pullrequest' => [
                    'overall' => [
                        'count' => $count,
                        'lastUpdated' => '2026-10-01T10:00:00.000+0000',
                        'stateCount' => 1,
                        'state' => $state,
                        'dataType' => 'pullrequest',
                        'open' => $state === 'OPEN',
                    ],
                    'byInstanceType' => ['bitbucket' => ['count' => $count, 'name' => 'Bitbucket']],
                ],
                'build' => ['overall' => ['count' => 0]],
            ],
        ],
        'isStale' => false,
    ]);
}

test('map extracts the pull request state and count from the development field', function (string $state, int $count, JiraPullRequestState $expected) {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $row = JiraIssueMapper::map(developmentPayload(developmentSummary($count, $state)), $user, null, null, 'customfield_10000');

    expect($row['pr_state'])->toBe($expected->value)
        ->and($row['pr_count'])->toBe($count);
})->with([
    'merged' => ['MERGED', 2, JiraPullRequestState::Merged],
    'open' => ['OPEN', 1, JiraPullRequestState::Open],
    'declined' => ['DECLINED', 3, JiraPullRequestState::Declined],
]);

test('map reads the legacy development summary format without cachedValue', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $legacy = json_encode(['summary' => ['pullrequest' => ['overall' => ['count' => 1, 'state' => 'OPEN']]]]);

    $row = JiraIssueMapper::map(developmentPayload($legacy), $user, null, null, 'customfield_10000');

    expect($row['pr_state'])->toBe('OPEN')
        ->and($row['pr_count'])->toBe(1);
});

test('map reads the JSON embedded in Jira development field text', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;
    $value = '{pullrequest={dataType=pullrequest, state=MERGED, stateCount=5}, build={count=9}, json='.developmentSummary(5, 'MERGED').'}';

    $row = JiraIssueMapper::map(developmentPayload($value), $user, null, null, 'customfield_10000');

    expect($row['pr_state'])->toBe('MERGED')
        ->and($row['pr_count'])->toBe(5);
});

test('live PR summary refresh is only needed for missing PR data with linked commits', function (mixed $value, bool $expected) {
    expect(JiraIssueMapper::needsPullRequestRefresh($value))->toBe($expected);
})->with([
    'no development data' => [null, false],
    'no linked activity' => [json_encode(['summary' => []], JSON_THROW_ON_ERROR), false],
    'complete PR summary' => [developmentSummary(2, 'MERGED'), false],
    'commits but no cached PRs' => [json_encode(['summary' => ['repository' => ['overall' => ['count' => 7]]]], JSON_THROW_ON_ERROR), true],
]);

test('map returns no pull request data when the development value has none', function (mixed $value) {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $row = JiraIssueMapper::map(developmentPayload($value), $user, null, null, 'customfield_10000');

    expect($row['pr_state'])->toBeNull()
        ->and($row['pr_count'])->toBeNull();
})->with([
    'missing field' => [null],
    'invalid json' => ['{not json'],
    'invalid embedded json' => ['{pullrequest={state=MERGED}, json={not json}}'],
    'zero pull requests' => [json_encode(['cachedValue' => ['summary' => ['pullrequest' => ['overall' => ['count' => 0]]]]])],
    'no pull request summary' => [json_encode(['cachedValue' => ['summary' => ['build' => ['overall' => ['count' => 2]]]]])],
]);

test('map keeps the pull request count but no state for an unknown state', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $row = JiraIssueMapper::map(developmentPayload(developmentSummary(1, 'SUPERSEDED')), $user, null, null, 'customfield_10000');

    expect($row['pr_state'])->toBeNull()
        ->and($row['pr_count'])->toBe(1);
});

test('pull request data is null when no development field id is given', function () {
    $user = User::factory()->make(['jira_site_url' => 'https://example.atlassian.net']);
    $user->id = 1;

    $row = JiraIssueMapper::map(developmentPayload(developmentSummary(2, 'MERGED')), $user);

    expect($row['pr_state'])->toBeNull()
        ->and($row['pr_count'])->toBeNull();
});
