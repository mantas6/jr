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
    'open and draft' => [['DRAFT', 'OPEN'], JiraPullRequestState::Open],
    'draft and merged' => [['MERGED', 'DRAFT'], JiraPullRequestState::Draft],
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

test('approval counts only for open or draft requests', function (array $pullRequests, bool $expected) {
    expect(JiraPullRequestState::hasApproval($pullRequests))->toBe($expected);
})->with([
    'approved open' => [[['status' => 'OPEN', 'approved' => true]], true],
    'approved draft' => [[['status' => 'DRAFT', 'approved' => true]], true],
    'unapproved open' => [[['status' => 'OPEN', 'approved' => false]], false],
    'approved merged with unapproved open' => [[['status' => 'MERGED', 'approved' => true], ['status' => 'OPEN', 'approved' => false]], false],
    'no requests' => [[], false],
]);

test('describe mentions approval only for open or draft requests', function () {
    expect(JiraPullRequestState::Open->describe(1, approved: true))->toBe('1 pull request, open, approved')
        ->and(JiraPullRequestState::Merged->describe(1, approved: true))->toBe('1 pull request, merged');
});
