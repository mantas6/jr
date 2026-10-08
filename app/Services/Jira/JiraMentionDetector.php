<?php

declare(strict_types=1);

namespace App\Services\Jira;

use Carbon\CarbonInterface;

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
        if ($accountId === '' || !is_array($node)) {
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

    /**
     * Find the time of the newest mention of the account made by someone else
     * in the given Jira comments, or null when there is none.
     *
     * A comment counts when its body mentions the account and it was not written
     * by that account. Its time is the `updated` timestamp (falling back to
     * `created`), except when the account itself made the last edit, in which
     * case `created` is used so the user's own edit is not treated as new activity.
     *
     * @param  mixed  $comments  The `comment.comments` list from a Jira issue payload.
     */
    public static function latestCommentMentionAt(mixed $comments, string $accountId): ?CarbonInterface
    {
        if ($accountId === '' || !is_array($comments)) {
            return null;
        }

        $latest = null;

        foreach ($comments as $comment) {
            if (!is_array($comment) || !self::mentions($comment['body'] ?? null, $accountId)) {
                continue;
            }

            if ((string) data_get($comment, 'author.accountId') === $accountId) {
                continue;
            }

            $timestamp = (string) data_get($comment, 'updateAuthor.accountId') === $accountId
                ? data_get($comment, 'created')
                : (data_get($comment, 'updated') ?? data_get($comment, 'created'));

            $mentionedAt = JiraIssueMapper::parseDate($timestamp);

            if ($mentionedAt !== null && ($latest === null || $mentionedAt->gt($latest))) {
                $latest = $mentionedAt;
            }
        }

        return $latest;
    }
}
