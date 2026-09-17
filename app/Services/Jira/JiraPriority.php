<?php

declare(strict_types=1);

namespace App\Services\Jira;

/**
 * Resolves Jira priority names — including custom schemes such as
 * `P1 - Highest` or `Major`/`Trivial` — to a canonical level, badge color,
 * and sort rank. The keyword list drives both the PHP resolution and the SQL
 * ordering expression so they stay in sync.
 */
class JiraPriority
{
    /**
     * Canonical priority levels keyed by name, with a sort rank (lower is more
     * important) and badge color.
     *
     * @var array<string, array{rank: int, color: string}>
     */
    private const LEVELS = [
        'Highest' => ['rank' => 1, 'color' => 'danger'],
        'High' => ['rank' => 2, 'color' => 'warning'],
        'Medium' => ['rank' => 3, 'color' => 'primary'],
        'Low' => ['rank' => 4, 'color' => 'success'],
        'Lowest' => ['rank' => 5, 'color' => 'gray'],
    ];

    /**
     * Keywords mapped to a canonical level, ordered so more specific keywords
     * (e.g. `highest`, `lowest`) are matched before their shorter substrings
     * (`high`, `low`). The first match wins.
     *
     * @var array<string, string>
     */
    private const KEYWORDS = [
        'blocker' => 'Highest',
        'highest' => 'Highest',
        'critical' => 'High',
        'high' => 'High',
        'medium' => 'Medium',
        'lowest' => 'Lowest',
        'low' => 'Low',
        'major' => 'High',
        'minor' => 'Low',
        'trivial' => 'Lowest',
    ];

    /**
     * `P1`..`P5` prefixes mapped to a canonical level, used as a fallback when
     * no keyword matches.
     *
     * @var array<int, string>
     */
    private const P_LEVELS = [
        1 => 'Highest',
        2 => 'High',
        3 => 'Medium',
        4 => 'Low',
        5 => 'Lowest',
    ];

    /**
     * The rank assigned to unknown or missing priorities. It sorts after every
     * known level.
     */
    private const UNKNOWN_RANK = 6;

    /**
     * Resolve the canonical level for a priority name, or null when unknown.
     * Keywords are matched case-insensitively (most specific first); a `P1`..`P5`
     * prefix is used as a fallback.
     */
    public static function level(?string $name): ?string
    {
        $normalized = mb_strtolower(mb_trim((string) $name));

        if ($normalized === '') {
            return null;
        }

        foreach (self::KEYWORDS as $keyword => $level) {
            if (str_contains($normalized, $keyword)) {
                return $level;
            }
        }

        if (preg_match('/^p([1-5])\b/i', $normalized, $matches) === 1) {
            return self::P_LEVELS[(int) $matches[1]];
        }

        return null;
    }

    /**
     * Map a priority name to a badge color. Unknown priorities fall back to gray.
     */
    public static function color(?string $name): string
    {
        $level = self::level($name);

        return $level === null ? 'gray' : self::LEVELS[$level]['color'];
    }

    /**
     * Map a priority name to its sort rank (1..5). Unknown priorities rank last.
     */
    public static function rank(?string $name): int
    {
        $level = self::level($name);

        return $level === null ? self::UNKNOWN_RANK : self::LEVELS[$level]['rank'];
    }

    /**
     * Build a portable SQL CASE expression that maps the given column to its
     * sort rank, mirroring level(): keyword LIKE patterns first (most specific
     * first, since CASE stops at the first match), then `P1`..`P5` prefixes,
     * else the unknown rank. Returns the expression and its bound parameters.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function rankCaseExpression(string $column): array
    {
        $whens = '';
        $bindings = [];

        foreach (self::KEYWORDS as $keyword => $level) {
            $whens .= ' WHEN LOWER('.$column.') LIKE ? THEN '.self::LEVELS[$level]['rank'];
            $bindings[] = '%'.$keyword.'%';
        }

        foreach (self::P_LEVELS as $digit => $level) {
            $whens .= ' WHEN LOWER('.$column.') LIKE ? THEN '.self::LEVELS[$level]['rank'];
            $bindings[] = 'p'.$digit.'%';
        }

        return ['CASE'.$whens.' ELSE '.self::UNKNOWN_RANK.' END', $bindings];
    }
}
