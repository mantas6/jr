<?php

use App\Services\Jira\JiraPullRequestState;

test('describe uses the singular noun for a single pull request', function () {
    expect(JiraPullRequestState::Open->describe(1))->toBe('1 pull request, open');
});

test('describe uses the plural noun for several pull requests', function () {
    expect(JiraPullRequestState::Merged->describe(2))->toBe('2 pull requests, merged');
});

test('PR status prioritizes open requests and ignores declined requests', function (array $statuses, ?JiraPullRequestState $expected) {
    expect(JiraPullRequestState::fromStatuses($statuses))->toBe($expected);
})->with([
    'open and merged' => [['MERGED', 'OPEN'], JiraPullRequestState::Open],
    'open and declined' => [['DECLINED', 'OPEN'], JiraPullRequestState::Open],
    'declined and merged' => [['MERGED', 'DECLINED'], JiraPullRequestState::Merged],
    'only declined' => [['DECLINED'], null],
    'all merged' => [['MERGED', 'MERGED'], JiraPullRequestState::Merged],
    'lowercase status' => [['merged', 'open'], JiraPullRequestState::Open],
    'unknown status' => [['MERGED', 'UNKNOWN'], null],
    'no requests' => [[], null],
]);

test('declined requests have no summary label or list icon', function () {
    expect(JiraPullRequestState::Declined->describe(2))->toBeNull()
        ->and(JiraPullRequestState::Declined->icon())->toBeNull();
});
