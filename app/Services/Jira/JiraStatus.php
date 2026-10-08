<?php

declare(strict_types=1);

namespace App\Services\Jira;

/**
 * Resolves Jira statuses to badge colors. Most statuses are colored by their
 * category, but testing statuses (e.g. `Testing`, `QA Testing`) get their own
 * color so they stand out from other in-progress work.
 */
class JiraStatus
{
    /**
     * Badge colors keyed by Jira status category.
     *
     * @var array<string, string>
     */
    private const CATEGORY_COLORS = [
        'Done' => 'success',
        'In Progress' => 'info',
    ];

    /**
     * The badge color for statuses whose name contains `test`.
     */
    private const TESTING_COLOR = 'warning';

    /**
     * Map a status name and category to a badge color. Testing statuses are
     * matched case-insensitively by name; everything else falls back to its
     * category color, or gray when the category is unknown.
     */
    public static function color(?string $status, ?string $statusCategory): string
    {
        if (str_contains(mb_strtolower((string) $status), 'test')) {
            return self::TESTING_COLOR;
        }

        return self::CATEGORY_COLORS[(string) $statusCategory] ?? 'gray';
    }
}
