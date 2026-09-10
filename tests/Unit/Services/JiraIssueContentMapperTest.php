<?php

use App\Services\Jira\JiraIssueContentMapper;
use Carbon\CarbonInterface;

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
