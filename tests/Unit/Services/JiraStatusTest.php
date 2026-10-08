<?php

use App\Services\Jira\JiraStatus;

test('it gives testing statuses a distinct warning color', function () {
    expect(JiraStatus::color('Testing', 'In Progress'))->toBe('warning')
        ->and(JiraStatus::color('In Testing', 'In Progress'))->toBe('warning')
        ->and(JiraStatus::color('QA TESTING', 'In Progress'))->toBe('warning');
});

test('it colors other statuses by their category', function () {
    expect(JiraStatus::color('Progress', 'In Progress'))->toBe('info')
        ->and(JiraStatus::color('Closed', 'Done'))->toBe('success')
        ->and(JiraStatus::color('New', 'To Do'))->toBe('gray');
});

test('it falls back to gray for missing status and category', function () {
    expect(JiraStatus::color(null, null))->toBe('gray')
        ->and(JiraStatus::color(null, 'Done'))->toBe('success');
});
