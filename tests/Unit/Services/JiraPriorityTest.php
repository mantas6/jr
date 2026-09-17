<?php

use App\Services\Jira\JiraPriority;

test('it resolves custom P-prefixed priority names to colors and ranks', function () {
    expect(JiraPriority::color('P1 - Highest'))->toBe('danger')
        ->and(JiraPriority::rank('P1 - Highest'))->toBe(1)
        ->and(JiraPriority::color('P2 - High'))->toBe('warning')
        ->and(JiraPriority::rank('P2 - High'))->toBe(2)
        ->and(JiraPriority::color('P3 - Medium'))->toBe('primary')
        ->and(JiraPriority::rank('P3 - Medium'))->toBe(3)
        ->and(JiraPriority::color('P4 - Low'))->toBe('success')
        ->and(JiraPriority::rank('P4 - Low'))->toBe(4);
});

test('it resolves plain priority names to colors', function () {
    expect(JiraPriority::color('Highest'))->toBe('danger')
        ->and(JiraPriority::color('Low'))->toBe('success')
        ->and(JiraPriority::color('Lowest'))->toBe('gray')
        ->and(JiraPriority::rank('Lowest'))->toBe(5)
        ->and(JiraPriority::color('Blocker'))->toBe('danger')
        ->and(JiraPriority::rank('Blocker'))->toBe(1);
});

test('it resolves alias keywords to the matching level', function () {
    expect(JiraPriority::color('Major'))->toBe('warning')
        ->and(JiraPriority::color('Trivial'))->toBe('gray');
});

test('it falls back to a bare P-prefix when no keyword matches', function () {
    expect(JiraPriority::color('P5'))->toBe('gray')
        ->and(JiraPriority::rank('P5'))->toBe(5);
});

test('it falls back to gray and the unknown rank for unknown or missing names', function () {
    expect(JiraPriority::color('Whatever'))->toBe('gray')
        ->and(JiraPriority::rank('Whatever'))->toBe(6)
        ->and(JiraPriority::color(null))->toBe('gray')
        ->and(JiraPriority::rank(null))->toBe(6);
});

test('it resolves the canonical level, preferring specific keywords over substrings', function () {
    expect(JiraPriority::level('P1 - Highest'))->toBe('Highest')
        ->and(JiraPriority::level('Lowest'))->toBe('Lowest')
        ->and(JiraPriority::level('High'))->toBe('High')
        ->and(JiraPriority::level('Minor'))->toBe('Low')
        ->and(JiraPriority::level('unknown'))->toBeNull();
});
