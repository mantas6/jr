<?php

use App\Services\Jira\JiraDuration;

test('it formats seconds using 8-hour days and 5-day weeks', function () {
    $seconds = (2 * 5 * 8 * 3600) + (8 * 3600) + (3 * 3600) + (30 * 60);

    expect(JiraDuration::format($seconds))->toBe('2w 1d 3h 30m')
        ->and(JiraDuration::format(5 * 8 * 3600))->toBe('1w')
        ->and(JiraDuration::format(9 * 3600))->toBe('1d 1h');
});

test('it omits zero parts', function () {
    expect(JiraDuration::format((5 * 8 * 3600) + (15 * 60)))->toBe('1w 15m')
        ->and(JiraDuration::format(90 * 60))->toBe('1h 30m');
});

test('it returns null when there is no estimate', function () {
    expect(JiraDuration::format(null))->toBeNull()
        ->and(JiraDuration::format(0))->toBeNull();
});

test('it shows sub-minute estimates as 0m', function () {
    expect(JiraDuration::format(45))->toBe('0m');
});
