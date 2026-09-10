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
    ];

    /**
     * The schema identifier Jira uses for the (instance-specific) Sprint field.
     */
    private const SPRINT_FIELD_SCHEMA = 'com.pyxis.greenhopper.jira:gh-sprint';

    /**
     * The maximum number of issues requested per search page.
     */
    private const MAX_RESULTS = 100;

    /**
     * The resolved Sprint custom field id (e.g. `customfield_10020`), or null
     * when the instance has no Sprint field. `false` means "not yet resolved".
     */
    private string|false|null $sprintFieldId = false;

    public function __construct(
        protected User $user,
        protected PendingRequest $request,
    ) {}

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
        if ($this->sprintFieldId !== false) {
            return $this->sprintFieldId;
        }

        try {
            $fields = $this->send(fn (PendingRequest $request): Response => $request->get('/rest/api/3/field'))->json();
        } catch (JiraApiException) {
            return $this->sprintFieldId = null;
        }

        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (is_array($field) && data_get($field, 'schema.custom') === self::SPRINT_FIELD_SCHEMA) {
                    return $this->sprintFieldId = (string) data_get($field, 'id');
                }
            }
        }

        return $this->sprintFieldId = null;
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
     * @return array<string, mixed>
     */
    public function getIssue(string $key): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get(
            "/rest/api/3/issue/{$key}",
            ['fields' => implode(',', self::ISSUE_FIELDS)],
        ))->json();
    }

    /**
     * Fetch an issue's description and comments, including Jira's rendered HTML.
     *
     * @return array<string, mixed>
     */
    public function getIssueContent(string $key): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get(
            "/rest/api/3/issue/{$key}",
            [
                'fields' => 'description,comment',
                'expand' => 'renderedFields',
            ],
        ))->json();
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
