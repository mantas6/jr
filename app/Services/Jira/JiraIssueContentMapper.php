<?php

declare(strict_types=1);

namespace App\Services\Jira;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class JiraIssueContentMapper
{
    /**
     * Map a Jira issue content payload (fetched with `expand=renderedFields`)
     * into the description HTML and a normalized list of comments.
     *
     * When a Jira site URL is given, inline attachment URLs in the rendered
     * HTML are rewritten to load through the authenticated local proxy so
     * embedded images render in the browser.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     description: string|null,
     *     comments: list<array{author: string, created: CarbonInterface|null, body: string}>,
     * }
     */
    public static function map(array $payload, ?string $jiraSiteUrl = null): array
    {
        return [
            'description' => self::nullableHtml(data_get($payload, 'renderedFields.description'), $jiraSiteUrl),
            'comments' => self::mapComments($payload, $jiraSiteUrl),
        ];
    }

    /**
     * Zip raw comment metadata with the rendered comment bodies, ordered
     * newest to oldest (Jira returns them oldest first).
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{author: string, created: CarbonInterface|null, body: string}>
     */
    private static function mapComments(array $payload, ?string $jiraSiteUrl = null): array
    {
        $rawComments = data_get($payload, 'fields.comment.comments');
        $renderedComments = data_get($payload, 'renderedFields.comment.comments');

        if (!is_array($rawComments)) {
            return [];
        }

        $rendered = is_array($renderedComments) ? array_values($renderedComments) : [];

        $comments = [];

        foreach (array_values($rawComments) as $index => $comment) {
            if (!is_array($comment)) {
                continue;
            }

            $comments[] = [
                'author' => (string) data_get($comment, 'author.displayName', 'Unknown'),
                'created' => self::parseDate(data_get($comment, 'created')),
                'body' => JiraAttachmentProxy::rewriteHtml(
                    (string) data_get($rendered[$index] ?? [], 'body', ''),
                    $jiraSiteUrl,
                ),
            ];
        }

        return array_reverse($comments);
    }

    /**
     * Return the rewritten HTML string, or null when it is empty.
     */
    private static function nullableHtml(mixed $value, ?string $jiraSiteUrl = null): ?string
    {
        if (!is_string($value) || mb_trim($value) === '') {
            return null;
        }

        return JiraAttachmentProxy::rewriteHtml($value, $jiraSiteUrl);
    }

    /**
     * Parse an optional Jira timestamp string into a Carbon instance.
     */
    private static function parseDate(mixed $value): ?CarbonInterface
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
