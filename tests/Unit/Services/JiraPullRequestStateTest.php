<?php

use App\Services\Jira\JiraPullRequestState;

test('describe uses the singular noun for a single pull request', function () {
    expect(JiraPullRequestState::Open->describe(1))->toBe('1 pull request, open');
});

test('describe uses the plural noun for several pull requests', function () {
    expect(JiraPullRequestState::Merged->describe(2))->toBe('2 pull requests, merged');
});

test('PR status keeps unfinished requests visible and only marks all merged as successful', function (array $statuses, ?JiraPullRequestState $expected) {
    expect(JiraPullRequestState::fromStatuses($statuses))->toBe($expected);
})->with([
    'open and merged' => [['MERGED', 'OPEN'], JiraPullRequestState::Open],
    'open and declined' => [['DECLINED', 'OPEN'], JiraPullRequestState::Open],
    'declined and merged' => [['MERGED', 'DECLINED'], JiraPullRequestState::Declined],
    'all merged' => [['MERGED', 'MERGED'], JiraPullRequestState::Merged],
    'lowercase status' => [['merged', 'open'], JiraPullRequestState::Open],
    'unknown status' => [['MERGED', 'UNKNOWN'], null],
    'no requests' => [[], null],
]);
