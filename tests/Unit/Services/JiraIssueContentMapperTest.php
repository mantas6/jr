<?php

use App\Services\Jira\JiraIssueContentMapper;
use Carbon\CarbonInterface;

test('map normalizes subtasks and builds links from the connected Jira site', function () {
    $result = JiraIssueContentMapper::map([
        'fields' => ['subtasks' => [
            [
                'key' => 'PROJ-2',
                'fields' => [
                    'summary' => 'Child task',
                    'status' => ['name' => 'In Progress', 'statusCategory' => ['name' => 'In Progress']],
                    'priority' => ['name' => 'High'],
                ],
            ],
            ['key' => 'PROJ-3'],
            null,
            ['fields' => ['summary' => 'Missing key']],
        ]],
    ], 'https://example.atlassian.net/');

    expect($result['subtasks'])->toBe([
        [
            'key' => 'PROJ-2',
            'summary' => 'Child task',
            'status' => 'In Progress',
            'status_category' => 'In Progress',
            'priority' => 'High',
            'url' => 'https://example.atlassian.net/browse/PROJ-2',
        ],
        [
            'key' => 'PROJ-3',
            'summary' => '',
            'status' => '',
            'status_category' => '',
            'priority' => '',
            'url' => 'https://example.atlassian.net/browse/PROJ-3',
        ],
    ]);
});

test('map returns no subtasks for missing, empty or invalid fields', function (mixed $subtasks) {
    expect(JiraIssueContentMapper::map(['fields' => ['subtasks' => $subtasks]])['subtasks'])->toBe([]);
})->with([
    'missing' => [null],
    'empty' => [[]],
    'invalid' => ['invalid'],
]);

test('mapPullRequests flags requests with at least one reviewer approval', function () {
    $mapped = JiraIssueContentMapper::mapPullRequests([
        ['id' => '17', 'reviewers' => [['name' => 'Alice', 'approved' => false], ['name' => 'Bob', 'approved' => true]]],
        ['id' => '18', 'reviewers' => [['name' => 'Alice', 'approved' => false]]],
    ]);

    expect(array_column($mapped, 'approved'))->toBe([true, false])
        ->and($mapped[0]['reviewers'])->toBe(['Alice (pending)', 'Bob (approved)']);
});

test('mapPullRequests blocks unsafe URLs and tolerates missing optional details', function () {
    $mapped = JiraIssueContentMapper::mapPullRequests([
        ['id' => '17', 'name' => 'A change', 'url' => 'javascript:alert(1)'],
        ['id' => '18', 'name' => 'Another change', 'url' => 'https://bitbucket.org/{workspace}/{repo}/pull-requests/18'],
    ]);

    expect($mapped[0]['url'])->toBeNull()
        ->and($mapped[0]['reviewers'])->toBe([])
        ->and($mapped[0]['updated'])->toBeNull()
        ->and($mapped[1]['url'])->toBe('https://bitbucket.org/{workspace}/{repo}/pull-requests/18');
});

/**
 * A Bitbucket Cloud pull request as returned by Jira's dev-status detail
 * endpoint, with UUID-keyed URLs and the slug only in `repositoryName`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bitbucketPullRequestPayload(array $overrides = []): array
{
    return [
        'author' => ['name' => 'Mantas Pauliukonis', 'avatar' => 'https://secure.gravatar.com/avatar/ca48'],
        'id' => '1864',
        'name' => 'SUPER-4206: add checksum verification command',
        'commentCount' => 0,
        'source' => [
            'branch' => 'SUPER-4206-math2-audit',
            'url' => 'https://bitbucket.org/%7B%7D/%7B36f6683b-0b66-49bf-a536-751b098e53dd%7D/branch/SUPER-4206-math2-audit',
        ],
        'destination' => [
            'branch' => 'dev',
            'url' => 'https://bitbucket.org/%7B%7D/%7B36f6683b-0b66-49bf-a536-751b098e53dd%7D/branch/dev',
        ],
        'reviewers' => [['name' => 'gytis.putvinskas', 'approved' => false]],
        'status' => 'DRAFT',
        'url' => 'https://bitbucket.org/{f492f7ff-6677-4603-af75-f702e29b0071}/{36f6683b-0b66-49bf-a536-751b098e53dd}/pull-requests/1864',
        'lastUpdate' => '2026-09-09T12:18:21.713+0000',
        'repositoryId' => '7b33366636363833622d306236362d343962662d613533362d3735316230393865353364647d',
        'repositoryName' => 'frontit-internal/superlotto-front',
        'repositoryUrl' => 'https://bitbucket.org/{}/{36f6683b-0b66-49bf-a536-751b098e53dd}',
        ...$overrides,
    ];
}

test('mapPullRequests rebuilds UUID-based Bitbucket links from the repository slug', function (string $url) {
    $mapped = JiraIssueContentMapper::mapPullRequests([bitbucketPullRequestPayload(['url' => $url])]);

    expect($mapped[0]['url'])->toBe('https://bitbucket.org/frontit-internal/superlotto-front/pull-requests/1864')
        ->and($mapped[0]['repository'])->toBe('frontit-internal/superlotto-front')
        ->and($mapped[0]['status'])->toBe('DRAFT');
})->with([
    'raw braces' => ['https://bitbucket.org/{f492f7ff-6677-4603-af75-f702e29b0071}/{36f6683b-0b66-49bf-a536-751b098e53dd}/pull-requests/1864'],
    'percent-encoded braces' => ['https://bitbucket.org/%7Bf492f7ff-6677-4603-af75-f702e29b0071%7D/%7B36f6683b-0b66-49bf-a536-751b098e53dd%7D/pull-requests/1864'],
]);

test('mapPullRequests prefers a slug-based repository URL when Jira reports one', function () {
    $mapped = JiraIssueContentMapper::mapPullRequests([bitbucketPullRequestPayload([
        'repositoryUrl' => 'https://bitbucket.org/frontit/superlotto-front/',
        'repositoryName' => 'superlotto-front',
    ])]);

    expect($mapped[0]['url'])->toBe('https://bitbucket.org/frontit/superlotto-front/pull-requests/1864');
});

test('mapPullRequests keeps the reported link when it is slug-based or no slug is available', function (array $overrides, string $expected) {
    $mapped = JiraIssueContentMapper::mapPullRequests([bitbucketPullRequestPayload($overrides)]);

    expect($mapped[0]['url'])->toBe($expected);
})->with([
    'repository name without workspace' => [
        ['repositoryName' => 'superlotto-front'],
        'https://bitbucket.org/{f492f7ff-6677-4603-af75-f702e29b0071}/{36f6683b-0b66-49bf-a536-751b098e53dd}/pull-requests/1864',
    ],
    'slug-based link' => [
        ['url' => 'https://github.com/acme/app/pull/1864'],
        'https://github.com/acme/app/pull/1864',
    ],
]);

test('map extracts the rendered description html', function () {
    $result = JiraIssueContentMapper::map([
        'renderedFields' => ['description' => '<p>Some <strong>details</strong>.</p>'],
    ]);

    expect($result['description'])->toBe('<p>Some <strong>details</strong>.</p>');
});

test('map returns a null description when it is missing or empty', function () {
    expect(JiraIssueContentMapper::map([])['description'])->toBeNull()
        ->and(JiraIssueContentMapper::map(['renderedFields' => ['description' => '   ']])['description'])->toBeNull();
});

test('map zips raw comment metadata with rendered bodies, newest first', function () {
    $result = JiraIssueContentMapper::map([
        'fields' => [
            'comment' => [
                'comments' => [
                    ['author' => ['displayName' => 'Alice'], 'created' => '2024-01-01T09:00:00.000+0000'],
                    ['author' => ['displayName' => 'Bob'], 'created' => '2024-01-02T10:00:00.000+0000'],
                ],
            ],
        ],
        'renderedFields' => [
            'comment' => [
                'comments' => [
                    ['body' => '<p>First</p>'],
                    ['body' => '<p>Second</p>'],
                ],
            ],
        ],
    ]);

    expect($result['comments'])->toHaveCount(2)
        ->and($result['comments'][0]['author'])->toBe('Bob')
        ->and($result['comments'][0]['body'])->toBe('<p>Second</p>')
        ->and($result['comments'][0]['created'])->toBeInstanceOf(CarbonInterface::class)
        ->and($result['comments'][1]['author'])->toBe('Alice')
        ->and($result['comments'][1]['body'])->toBe('<p>First</p>');
});

test('map falls back gracefully when comment fields are missing', function () {
    $result = JiraIssueContentMapper::map([
        'fields' => [
            'comment' => [
                'comments' => [
                    ['author' => [], 'created' => null],
                ],
            ],
        ],
    ]);

    expect($result['comments'])->toHaveCount(1)
        ->and($result['comments'][0]['author'])->toBe('Unknown')
        ->and($result['comments'][0]['body'])->toBe('')
        ->and($result['comments'][0]['created'])->toBeNull();
});

test('map returns an empty comment list when none are present', function () {
    expect(JiraIssueContentMapper::map([])['comments'])->toBe([]);
});

test('map converts the raw description ADF into markdown', function () {
    $result = JiraIssueContentMapper::map([
        'renderedFields' => ['description' => '<p>Some <strong>details</strong>.</p>'],
        'fields' => [
            'description' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [
                        ['type' => 'text', 'text' => 'Some '],
                        ['type' => 'text', 'text' => 'details', 'marks' => [['type' => 'strong']]],
                        ['type' => 'text', 'text' => '.'],
                    ]],
                ],
            ],
        ],
    ]);

    expect($result['descriptionMarkdown'])->toBe('Some **details**.');
});

test('map falls back to stripped html for the description markdown when ADF is missing', function () {
    $result = JiraIssueContentMapper::map([
        'renderedFields' => ['description' => '<p>Just <strong>html</strong>.</p>'],
    ]);

    expect($result['descriptionMarkdown'])->toBe('Just html.');
});

test('map returns a null description markdown when both ADF and html are missing', function () {
    expect(JiraIssueContentMapper::map([])['descriptionMarkdown'])->toBeNull();
});

test('map converts each comment ADF body into markdown, newest first', function () {
    $result = JiraIssueContentMapper::map([
        'fields' => [
            'comment' => [
                'comments' => [
                    [
                        'author' => ['displayName' => 'Alice'],
                        'created' => '2024-01-01T09:00:00.000+0000',
                        'body' => [
                            'type' => 'doc',
                            'content' => [
                                ['type' => 'paragraph', 'content' => [
                                    ['type' => 'text', 'text' => 'Hello ', 'marks' => []],
                                    ['type' => 'text', 'text' => 'there', 'marks' => [['type' => 'em']]],
                                ]],
                            ],
                        ],
                    ],
                    [
                        'author' => ['displayName' => 'Bob'],
                        'created' => '2024-01-02T10:00:00.000+0000',
                        'body' => 'not-an-array',
                    ],
                ],
            ],
        ],
        'renderedFields' => [
            'comment' => [
                'comments' => [
                    ['body' => '<p>Hello <em>there</em></p>'],
                    ['body' => '<p>Bob&rsquo;s reply</p>'],
                ],
            ],
        ],
    ]);

    expect($result['comments'][0]['author'])->toBe('Bob')
        ->and($result['comments'][0]['markdown'])->toBe('Bob’s reply')
        ->and($result['comments'][1]['author'])->toBe('Alice')
        ->and($result['comments'][1]['markdown'])->toBe('Hello *there*');
});
