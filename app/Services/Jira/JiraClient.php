<?php

declare(strict_types=1);

namespace App\Services\Jira;

use App\Models\User;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class JiraClient
{
    /**
     * The Jira issue fields requested when searching or fetching issues.
     *
     * @var list<string>
     */
    private const ISSUE_FIELDS = [
        'summary',
        'status',
        'issuetype',
        'priority',
        'assignee',
        'reporter',
        'created',
        'updated',
        'timeoriginalestimate',
    ];

    /**
     * The schema identifier Jira uses for the (instance-specific) Sprint field.
     */
    private const SPRINT_FIELD_SCHEMA = 'com.pyxis.greenhopper.jira:gh-sprint';

    /**
     * The schema identifier Jira uses for the (instance-specific) Development
     * summary field, which holds linked pull request, branch and build counts.
     */
    private const DEVELOPMENT_FIELD_SCHEMA = 'com.atlassian.jira.plugins.jira-development-integration-plugin:devsummarycf';

    /**
     * The maximum number of issues (or comments) requested per page.
     */
    private const MAX_RESULTS = 100;

    /**
     * The memoized `/rest/api/3/field` response, or null when not yet fetched.
     * A failed lookup is memoized as an empty list.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $fields = null;

    public function __construct(protected User $user, protected PendingRequest $request) {}

    /**
     * Build a client for the given user's stored Jira credentials.
     */
    public static function forUser(User $user): static
    {
        $request = Http::baseUrl((string) $user->jira_site_url)
            ->withBasicAuth((string) $user->jira_email, (string) $user->jira_api_token)
            ->acceptJson();

        return new self($user, $request);
    }

    /**
     * Fetch the authenticated Atlassian account (`/myself`).
     *
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get('/rest/api/3/myself'))->json();
    }

    /**
     * Resolve the instance-specific Sprint custom field id (e.g.
     * `customfield_10020`), or null when the Jira instance has no Sprint field.
     *
     * The lookup is memoized and never aborts a sync: any failure resolves to
     * null so issues are still synced without sprint data.
     */
    public function sprintFieldId(): ?string
    {
        return $this->customFieldIdForSchema(self::SPRINT_FIELD_SCHEMA);
    }

    /**
     * Resolve the instance-specific Development summary custom field id (e.g.
     * `customfield_10000`), or null when the Jira instance has no such field.
     *
     * Shares the memoized field lookup with {@see self::sprintFieldId()} and
     * likewise resolves to null on failure.
     */
    public function developmentFieldId(): ?string
    {
        return $this->customFieldIdForSchema(self::DEVELOPMENT_FIELD_SCHEMA);
    }

    /**
     * Search issues using JQL with cursor-based pagination.
     *
     * @param  list<string>  $extraFields  Additional issue field ids to request.
     * @return array{issues: list<array<string, mixed>>, nextPageToken?: string|null, isLast?: bool}
     */
    public function searchIssues(string $jql, ?string $nextPageToken = null, array $extraFields = []): array
    {
        $query = [
            'jql' => $jql,
            'fields' => implode(',', [...self::ISSUE_FIELDS, ...$extraFields]),
            'maxResults' => self::MAX_RESULTS,
        ];

        if ($nextPageToken !== null) {
            $query['nextPageToken'] = $nextPageToken;
        }

        return $this->send(fn (PendingRequest $request): Response => $request->get('/rest/api/3/search/jql', $query))->json();
    }

    /**
     * Fetch a single issue by key.
     *
     * @param  list<string>  $extraFields  Additional issue field ids to request.
     * @return array<string, mixed>
     */
    public function getIssue(string $key, array $extraFields = []): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get(
            "/rest/api/3/issue/{$key}",
            ['fields' => implode(',', [...self::ISSUE_FIELDS, ...$extraFields])],
        ))->json();
    }

    /**
     * Fetch an issue's description, comments and subtasks, including rendered HTML.
     *
     * @return array<string, mixed>
     */
    public function getIssueContent(string $key): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get(
            "/rest/api/3/issue/{$key}",
            [
                'fields' => 'description,comment,subtasks',
                'expand' => 'renderedFields',
            ],
        ))->json();
    }

    /**
     * Fetch all child issues of an Epic, following cursor pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function getChildIssues(string $key): array
    {
        $issues = [];
        $nextPageToken = null;

        do {
            $page = $this->searchIssues('parent = '.json_encode($key, JSON_THROW_ON_ERROR).' ORDER BY key ASC', $nextPageToken);
            $issues = [...$issues, ...$page['issues']];
            $nextPageToken = $page['nextPageToken'] ?? null;
        } while (!($page['isLast'] ?? ($nextPageToken === null)) && $nextPageToken !== null);

        return $issues;
    }

    /**
     * Fetch linked pull requests from the providers listed in Jira's development summary.
     *
     * @return list<array<string, mixed>>
     */
    public function getPullRequests(string $issueId): array
    {
        $summary = $this->getDevelopmentSummary($issueId);
        $providers = data_get($summary, 'summary.pullrequest.byInstanceType', []);

        if (!is_array($providers)) {
            return [];
        }

        $pullRequests = [];

        foreach (array_keys($providers) as $provider) {
            $payload = $this->send(fn (PendingRequest $request): Response => $request->get(
                '/rest/dev-status/1.0/issue/detail',
                ['issueId' => $issueId, 'applicationType' => $provider, 'dataType' => 'pullrequest'],
            ))->json();
            $details = data_get($payload, 'detail', []);

            foreach (is_array($details) ? $details : [] as $detail) {
                $requests = data_get($detail, 'pullRequests', []);

                if (is_array($requests)) {
                    $pullRequests = [...$pullRequests, ...array_values(array_filter($requests, is_array(...)))];
                }
            }
        }

        return $pullRequests;
    }

    /**
     * Fetch Jira's live development summary when the cached custom field is incomplete.
     *
     * @return array<string, mixed>
     */
    public function getDevelopmentSummary(string $issueId): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get(
            '/rest/dev-status/1.0/issue/summary',
            ['issueId' => $issueId],
        ))->json();
    }

    /**
     * Fetch every comment on an issue, following Jira's offset pagination.
     *
     * Used when an embedded `comment` field was truncated (its `total` exceeds
     * the number of comments returned).
     *
     * @return list<array<string, mixed>>
     */
    public function getAllComments(string $key): array
    {
        $comments = [];
        $startAt = 0;

        do {
            $page = $this->send(fn (PendingRequest $request): Response => $request->get(
                "/rest/api/3/issue/{$key}/comment",
                ['startAt' => $startAt, 'maxResults' => self::MAX_RESULTS],
            ))->json();

            $pageComments = array_values(array_filter((array) ($page['comments'] ?? []), is_array(...)));
            $comments = [...$comments, ...$pageComments];
            $startAt += count($pageComments);
            $total = (int) ($page['total'] ?? 0);
        } while ($pageComments !== [] && $startAt < $total);

        return $comments;
    }

    /**
     * Fetch the raw bytes of an attachment (or thumbnail) by its Jira path.
     *
     * The path is a site-relative Jira URL such as
     * `/rest/api/3/attachment/content/134715`; validate it with
     * {@see JiraAttachmentProxy::isAllowedPath()} before calling.
     */
    public function getAttachment(string $path): Response
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get($path));
    }

    /**
     * Fetch the available transitions for an issue.
     *
     * @return list<array<string, mixed>>
     */
    public function getTransitions(string $key): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get("/rest/api/3/issue/{$key}/transitions"));

        return $response->json('transitions', []);
    }

    /**
     * Apply a transition to an issue.
     */
    public function transitionIssue(string $key, string $transitionId): void
    {
        $this->send(fn (PendingRequest $request): Response => $request->post(
            "/rest/api/3/issue/{$key}/transitions",
            ['transition' => ['id' => $transitionId]],
        ));
    }

    /**
     * Add a comment to an issue.
     *
     * @param  array<string, mixed>  $adfBody  The comment body as an ADF document.
     * @return array<string, mixed>
     */
    public function addComment(string $issueKey, array $adfBody): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->post(
            "/rest/api/3/issue/{$issueKey}/comment",
            ['body' => $adfBody],
        ))->json();
    }

    /**
     * Search users assignable to the given project.
     *
     * @return list<array<string, mixed>>
     */
    public function searchAssignableUsers(string $projectKey, string $query): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get('/rest/api/3/user/assignable/search', [
            'project' => $projectKey,
            'query' => $query,
        ]))->json();
    }

    /**
     * Assign (or unassign, when `$accountId` is null) an issue.
     */
    public function assignIssue(string $key, ?string $accountId): void
    {
        $this->send(fn (PendingRequest $request): Response => $request->put(
            "/rest/api/3/issue/{$key}/assignee",
            ['accountId' => $accountId],
        ));
    }

    /**
     * Find the id of the custom field with the given `schema.custom` identifier.
     */
    private function customFieldIdForSchema(string $schema): ?string
    {
        foreach ($this->fields() as $field) {
            if (data_get($field, 'schema.custom') === $schema) {
                return (string) data_get($field, 'id');
            }
        }

        return null;
    }

    /**
     * Fetch (once) the instance's field definitions, resolving to an empty list
     * when the lookup fails.
     *
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }

        try {
            $fields = $this->send(fn (PendingRequest $request): Response => $request->get('/rest/api/3/field'))->json();
        } catch (JiraApiException) {
            return $this->fields = [];
        }

        return $this->fields = is_array($fields)
            ? array_values(array_filter($fields, is_array(...)))
            : [];
    }

    /**
     * Execute an HTTP call, converting network and error responses into JiraApiException.
     *
     * @param  Closure(PendingRequest): Response  $callback
     */
    private function send(Closure $callback): Response
    {
        try {
            $response = $callback($this->request);
        } catch (ConnectionException $exception) {
            throw new JiraApiException(
                "Could not reach Jira at {$this->user->jira_site_url}: {$exception->getMessage()}",
                0,
                $exception,
            );
        }

        if ($response->failed()) {
            throw $this->exceptionForResponse($response);
        }

        return $response;
    }

    /**
     * Build a readable JiraApiException from a failed response.
     */
    private function exceptionForResponse(Response $response): JiraApiException
    {
        $status = $response->status();

        $message = match ($status) {
            401 => 'Jira rejected the credentials (401). Check your email and API token.',
            403 => 'Jira denied access (403). Your account lacks permission for this action.',
            404 => 'The requested Jira resource was not found (404).',
            default => $this->messageFromBody($response),
        };

        return new JiraApiException($message, $status);
    }

    /**
     * Extract Jira's error details from the response body, falling back to the status code.
     */
    private function messageFromBody(Response $response): string
    {
        $status = $response->status();
        $body = $response->json();

        if (is_array($body)) {
            $errorMessages = $body['errorMessages'] ?? [];

            if (is_array($errorMessages) && $errorMessages !== []) {
                return "Jira request failed ({$status}): ".implode(' ', array_map('strval', $errorMessages));
            }

            $errors = $body['errors'] ?? [];

            if (is_array($errors) && $errors !== []) {
                return "Jira request failed ({$status}): ".implode(' ', array_map('strval', $errors));
            }
        }

        return "Jira request failed with status {$status}.";
    }
}
