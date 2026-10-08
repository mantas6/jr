<?php

declare(strict_types=1);

namespace App\Services\Jira;

/**
 * Formats Jira time-tracking durations (stored in seconds) the way Jira shows
 * them, e.g. `2w 1d 3h 30m`, using Jira's default 8-hour day and 5-day week.
 */
class JiraDuration
{
    /**
     * The number of seconds in each Jira time unit, largest first.
     *
     * @var array<string, int>
     */
    private const UNITS = [
        'w' => 5 * 8 * 3600,
        'd' => 8 * 3600,
        'h' => 3600,
        'm' => 60,
    ];

    /**
     * Format a duration in seconds as a Jira-style string, omitting zero parts.
     * Returns null when there is no duration (null or non-positive), and `0m`
     * for a positive duration shorter than a minute.
     */
    public static function format(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $parts = [];

        foreach (self::UNITS as $suffix => $unitSeconds) {
            $count = intdiv($seconds, $unitSeconds);

            if ($count > 0) {
                $parts[] = $count.$suffix;
                $seconds -= $count * $unitSeconds;
            }
        }

        return $parts === [] ? '0m' : implode(' ', $parts);
    }
}
