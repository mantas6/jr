<?php

declare(strict_types=1);

namespace App\Services\Jira;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

class JiraReleaseMapper
{
    /**
     * Map a Jira project version payload into a row suitable for
     * `JiraRelease::upsert()`.
     *
     * `raw` is JSON-encoded, the flags are cast to integers, the dates are
     * formatted as `Y-m-d` and the sync time as `Y-m-d H:i:s`.
     *
     * @param  array<string, mixed>  $version
     * @return array{
     *     user_id: int,
     *     jira_id: string,
     *     name: string,
     *     description: string|null,
     *     released: int,
     *     archived: int,
     *     overdue: int,
     *     start_date: string|null,
     *     release_date: string|null,
     *     jira_url: string,
     *     raw: string,
     *     last_synced_at: string,
     * }
     */
    public static function mapForUpsert(array $version, User $user, CarbonInterface $syncedAt): array
    {
        $jiraId = (string) ($version['id'] ?? '');
        $description = $version['description'] ?? null;

        return [
            'user_id' => $user->id,
            'jira_id' => $jiraId,
            'name' => (string) ($version['name'] ?? ''),
            'description' => is_string($description) && $description !== '' ? $description : null,
            'released' => (int) (bool) ($version['released'] ?? false),
            'archived' => (int) (bool) ($version['archived'] ?? false),
            'overdue' => (int) (bool) ($version['overdue'] ?? false),
            'start_date' => self::parseDate($version['startDate'] ?? null),
            'release_date' => self::parseDate($version['releaseDate'] ?? null),
            'jira_url' => self::url($user, $jiraId),
            'raw' => (string) json_encode($version),
            'last_synced_at' => $syncedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The browse link to the version's page in Jira.
     */
    public static function url(User $user, string $jiraId): string
    {
        return mb_rtrim((string) $user->jira_site_url, '/')
            .'/projects/'.rawurlencode((string) $user->jira_project_key)
            .'/versions/'.rawurlencode($jiraId);
    }

    /**
     * Normalise a Jira version date (`2024-03-01`) to `Y-m-d`, or null when
     * absent or unparseable.
     */
    private static function parseDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
