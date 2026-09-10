<?php

declare(strict_types=1);

namespace App\Services\Jira;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class JiraIssueMapper
{
    /**
     * Map a Jira issue payload into a local `jira_issues` row.
     *
     * `raw` is returned as an array (matching the model's `array` cast) and the
     * timestamps as Carbon instances. This form is suitable for `Model::update()`.
     * For `JiraIssue::upsert()`, use {@see self::mapForUpsert()} which JSON-encodes
     * `raw` and formats timestamps as DB strings.
     *
     * @param  array<string, mixed>  $issue
     * @param  string|null  $sprintFieldId  The instance-specific Sprint field id (e.g. `customfield_10020`).
     * @return array{
     *     user_id: int,
     *     jira_id: string,
     *     jira_key: string,
     *     summary: string,
     *     status: string,
     *     status_id: string,
     *     status_category: string,
     *     issue_type: string,
     *     priority: string|null,
     *     assignee_account_id: string|null,
     *     assignee_name: string|null,
     *     reporter_name: string|null,
     *     sprints: list<string>|null,
     *     jira_url: string,
     *     jira_created_at: CarbonInterface|null,
     *     jira_updated_at: CarbonInterface|null,
     *     raw: array<string, mixed>,
     *     last_synced_at: CarbonInterface,
     * }
     */
    public static function map(array $issue, User $user, ?CarbonInterface $syncedAt = null, ?string $sprintFieldId = null): array
    {
        /** @var array<string, mixed> $fields */
        $fields = is_array($issue['fields'] ?? null) ? $issue['fields'] : [];

        $syncedAt ??= Carbon::now();
        $key = (string) ($issue['key'] ?? '');

        return [
            'user_id' => $user->id,
            'jira_id' => (string) ($issue['id'] ?? ''),
            'jira_key' => $key,
            'summary' => (string) data_get($fields, 'summary', ''),
            'status' => (string) data_get($fields, 'status.name', ''),
            'status_id' => (string) data_get($fields, 'status.id', ''),
            'status_category' => (string) data_get($fields, 'status.statusCategory.name', ''),
            'issue_type' => (string) data_get($fields, 'issuetype.name', ''),
            'priority' => self::nullableString(data_get($fields, 'priority.name')),
            'assignee_account_id' => self::nullableString(data_get($fields, 'assignee.accountId')),
            'assignee_name' => self::nullableString(data_get($fields, 'assignee.displayName')),
            'reporter_name' => self::nullableString(data_get($fields, 'reporter.displayName')),
            'sprints' => $sprintFieldId !== null ? self::extractSprintNames(data_get($fields, $sprintFieldId)) : null,
            'jira_url' => rtrim((string) $user->jira_site_url, '/').'/browse/'.$key,
            'jira_created_at' => self::parseDate(data_get($fields, 'created')),
            'jira_updated_at' => self::parseDate(data_get($fields, 'updated')),
            'raw' => $fields,
            'last_synced_at' => $syncedAt,
        ];
    }

    /**
     * Map a Jira issue payload into a row suitable for `JiraIssue::upsert()`.
     *
     * `raw` is JSON-encoded and all timestamps are formatted as `Y-m-d H:i:s`
     * strings so the values can be written directly by the query builder.
     *
     * @param  array<string, mixed>  $issue
     * @param  string|null  $sprintFieldId  The instance-specific Sprint field id (e.g. `customfield_10020`).
     * @return array<string, string|int|null>
     */
    public static function mapForUpsert(array $issue, User $user, ?CarbonInterface $syncedAt = null, ?string $sprintFieldId = null): array
    {
        $row = self::map($issue, $user, $syncedAt, $sprintFieldId);

        $row['sprints'] = $row['sprints'] === null ? null : json_encode($row['sprints']);
        $row['raw'] = json_encode($row['raw']);
        $row['jira_created_at'] = $row['jira_created_at']?->format('Y-m-d H:i:s');
        $row['jira_updated_at'] = $row['jira_updated_at']?->format('Y-m-d H:i:s');
        $row['last_synced_at'] = $row['last_synced_at']->format('Y-m-d H:i:s');

        /** @var array<string, string|int|null> $row */
        return $row;
    }

    /**
     * Parse an optional Jira timestamp string into a Carbon instance.
     */
    private static function parseDate(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    /**
     * Cast a value to a non-empty string, or null when absent.
     */
    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * Extract sprint names from a Jira Sprint custom field value.
     *
     * The modern search API returns an array of sprint objects (each with a
     * `name`), while older payloads may return the legacy
     * `...[name=Sprint 1,...]` string form. Both are handled.
     *
     * @return list<string>|null
     */
    private static function extractSprintNames(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $names = [];

        foreach ($value as $sprint) {
            if (is_array($sprint) && isset($sprint['name'])) {
                $names[] = (string) $sprint['name'];
            } elseif (is_string($sprint) && preg_match('/name=([^,]+)/', $sprint, $matches) === 1) {
                $names[] = $matches[1];
            }
        }

        return $names === [] ? null : $names;
    }
}
