<?php

declare(strict_types=1);

namespace App\Services\Jira;

class JiraMentionDetector
{
    /**
     * Determine whether the given Atlassian Document Format (ADF) node — or any
     * of its descendants — mentions the account id.
     *
     * ADF represents a mention as `{"type": "mention", "attrs": {"id": "..."}}`.
     */
    public static function mentions(mixed $node, string $accountId): bool
    {
        if ($accountId === '' || ! is_array($node)) {
            return false;
        }

        if (($node['type'] ?? null) === 'mention' && (string) data_get($node, 'attrs.id') === $accountId) {
            return true;
        }

        foreach ($node as $child) {
            if (is_array($child) && self::mentions($child, $accountId)) {
                return true;
            }
        }

        return false;
    }
}
