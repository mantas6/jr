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
     *     descriptionMarkdown: string|null,
     *     comments: list<array{author: string, created: CarbonInterface|null, body: string, markdown: string}>,
     *     subtasks: list<array{key: string, summary: string, status: string, status_category: string, priority: string, url: string|null}>,
     * }
     */
    public static function map(array $payload, ?string $jiraSiteUrl = null): array
    {
        $description = self::nullableHtml(data_get($payload, 'renderedFields.description'), $jiraSiteUrl);

        return [
            'description' => $description,
            'descriptionMarkdown' => self::markdown(data_get($payload, 'fields.description'), $description),
            'comments' => self::mapComments($payload, $jiraSiteUrl),
            'subtasks' => self::mapSubtasks($payload, $jiraSiteUrl),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pullRequests
     * @return list<array{title: string, url: string|null, status: string, repository: string, source: string, destination: string, author: string, reviewers: list<string>, updated: CarbonInterface|null}>
     */
    public static function mapPullRequests(array $pullRequests): array
    {
        $mapped = [];

        foreach ($pullRequests as $pullRequest) {
            $url = $pullRequest['url'] ?? null;
            $reviewers = $pullRequest['reviewers'] ?? [];
            $reviewerNames = [];

            foreach (is_array($reviewers) ? $reviewers : [] as $reviewer) {
                if (is_array($reviewer)) {
                    $reviewerNames[] = (string) ($reviewer['name'] ?? 'Unknown').(!empty($reviewer['approved']) ? ' (approved)' : ' (pending)');
                }
            }

            $mapped[] = [
                'title' => '#'.(string) ($pullRequest['id'] ?? '').' '.(string) ($pullRequest['name'] ?? ''),
                'url' => is_string($url) && in_array(mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null,
                'status' => mb_strtoupper((string) ($pullRequest['status'] ?? '')),
                'repository' => (string) ($pullRequest['repositoryName'] ?? ''),
                'source' => (string) data_get($pullRequest, 'source.branch', ''),
                'destination' => (string) data_get($pullRequest, 'destination.branch', ''),
                'author' => (string) data_get($pullRequest, 'author.name', ''),
                'reviewers' => $reviewerNames,
                'updated' => self::parseDate($pullRequest['lastUpdate'] ?? null),
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{key: string, summary: string, status: string, status_category: string, priority: string, url: string|null}>
     */
    private static function mapSubtasks(array $payload, ?string $jiraSiteUrl): array
    {
        $subtasks = data_get($payload, 'fields.subtasks');

        if (!is_array($subtasks)) {
            return [];
        }

        $mapped = [];

        foreach ($subtasks as $subtask) {
            if (!is_array($subtask) || !is_string($key = $subtask['key'] ?? null) || $key === '') {
                continue;
            }

            $mapped[] = [
                'key' => $key,
                'summary' => (string) data_get($subtask, 'fields.summary', ''),
                'status' => (string) data_get($subtask, 'fields.status.name', ''),
                'status_category' => (string) data_get($subtask, 'fields.status.statusCategory.name', ''),
                'priority' => (string) data_get($subtask, 'fields.priority.name', ''),
                'url' => $jiraSiteUrl === null ? null : mb_rtrim($jiraSiteUrl, '/').'/browse/'.rawurlencode($key),
            ];
        }

        return $mapped;
    }

    /**
     * Zip raw comment metadata with the rendered comment bodies, ordered
     * newest to oldest (Jira returns them oldest first).
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{author: string, created: CarbonInterface|null, body: string, markdown: string}>
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

            $body = JiraAttachmentProxy::rewriteHtml(
                (string) data_get($rendered[$index] ?? [], 'body', ''),
                $jiraSiteUrl,
            );

            $comments[] = [
                'author' => (string) data_get($comment, 'author.displayName', 'Unknown'),
                'created' => self::parseDate(data_get($comment, 'created')),
                'body' => $body,
                'markdown' => (string) self::markdown(data_get($comment, 'body'), $body),
            ];
        }

        return array_reverse($comments);
    }

    /**
     * Convert an ADF document to Markdown, falling back to the plain text of
     * the rendered HTML when the raw ADF is missing or not an array.
     */
    private static function markdown(mixed $adf, ?string $renderedHtml): ?string
    {
        if (is_array($adf)) {
            return JiraAdfToMarkdown::convert($adf);
        }

        if (!is_string($renderedHtml) || mb_trim($renderedHtml) === '') {
            return null;
        }

        return mb_trim(html_entity_decode(strip_tags($renderedHtml), ENT_QUOTES | ENT_HTML5));
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
