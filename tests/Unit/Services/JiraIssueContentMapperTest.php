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
