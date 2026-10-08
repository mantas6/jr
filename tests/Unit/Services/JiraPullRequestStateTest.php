<?php

use App\Services\Jira\JiraPullRequestState;

test('describe uses the singular noun for a single pull request', function () {
    expect(JiraPullRequestState::Open->describe(1))->toBe('1 pull request, open');
});

test('describe uses the plural noun for several pull requests', function () {
    expect(JiraPullRequestState::Merged->describe(2))->toBe('2 pull requests, merged');
});
