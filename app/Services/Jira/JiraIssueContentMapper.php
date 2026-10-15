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
     * @return list<array{title: string, url: string|null, status: string, repository: string, source: string, destination: string, author: string, reviewers: list<string>, approved: bool, updated: CarbonInterface|null}>
     */
    public static function mapPullRequests(array $pullRequests): array
    {
        $mapped = [];

        foreach ($pullRequests as $pullRequest) {
            $reviewers = $pullRequest['reviewers'] ?? [];
            $reviewerNames = [];
            $approved = false;

            foreach (is_array($reviewers) ? $reviewers : [] as $reviewer) {
                if (is_array($reviewer)) {
                    $reviewerNames[] = (string) ($reviewer['name'] ?? 'Unknown').(!empty($reviewer['approved']) ? ' (approved)' : ' (pending)');
                    $approved = $approved || !empty($reviewer['approved']);
                }
            }

            $mapped[] = [
                'title' => '#'.(string) ($pullRequest['id'] ?? '').' '.(string) ($pullRequest['name'] ?? ''),
                'url' => self::pullRequestUrl($pullRequest),
                'status' => mb_strtoupper((string) ($pullRequest['status'] ?? '')),
                'repository' => (string) ($pullRequest['repositoryName'] ?? ''),
                'source' => (string) data_get($pullRequest, 'source.branch', ''),
                'destination' => (string) data_get($pullRequest, 'destination.branch', ''),
                'author' => (string) data_get($pullRequest, 'author.name', ''),
                'reviewers' => $reviewerNames,
                'approved' => $approved,
                'updated' => self::parseDate($pullRequest['lastUpdate'] ?? null),
            ];
        }

        return $mapped;
    }

    /**
     * Build a working link to a pull request.
     *
     * Bitbucket Cloud reports PR and repository URLs keyed by workspace and
     * repository UUIDs (e.g. `https://bitbucket.org/{uuid}/{uuid}/pull-requests/17`),
     * which Bitbucket does not resolve. When the reported URL is UUID-based,
     * the link is rebuilt from a slug-based `repositoryUrl` or, failing that,
     * the `workspace/repository` slug in `repositoryName`. Non-HTTP URLs are
     * rejected; anything else is returned as reported.
     *
     * @param  array<string, mixed>  $pullRequest
     */
    private static function pullRequestUrl(array $pullRequest): ?string
    {
        $url = self::httpUrl($pullRequest['url'] ?? null);
        $id = (string) ($pullRequest['id'] ?? '');

        if ($url === null || !self::isUuidUrl($url) || $id === '') {
            return $url;
        }

        $repositoryUrl = self::httpUrl($pullRequest['repositoryUrl'] ?? null);

        if ($repositoryUrl !== null && !self::isUuidUrl($repositoryUrl)) {
            return mb_rtrim($repositoryUrl, '/').'/pull-requests/'.rawurlencode($id);
        }

        $repositoryName = $pullRequest['repositoryName'] ?? null;

        if (is_string($repositoryName) && preg_match('#^[\w.-]+/[\w.-]+$#', $repositoryName) === 1) {
            $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);

            return $origin.'/'.$repositoryName.'/pull-requests/'.rawurlencode($id);
        }

        return $url;
    }

    /**
     * Return the value when it is an http(s) URL, otherwise null.
     */
    private static function httpUrl(mixed $value): ?string
    {
        return is_string($value) && in_array(mb_strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $value
            : null;
    }

    /**
     * Whether the URL path addresses its workspace or repository by a braced
     * UUID (`{...}`, raw or percent-encoded) rather than by slug.
     */
    private static function isUuidUrl(string $url): bool
    {
        return str_contains(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '{');
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
