<?php

declare(strict_types=1);

namespace App\Services\Jira;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Caches the target statuses reachable from a given issue type + status, per user
 * and project. The sync job warms this cache and the inline status dropdown reads
 * from it, so the UI never triggers a live Jira call while rendering.
 */
final class JiraTransitionsCache
{
    /**
     * How long a warmed transitions entry stays fresh, in seconds.
     */
    private const TTL_SECONDS = 3600;

    /**
     * Build the cache key for a user's transitions from a given issue type + status.
     */
    public static function key(User $user, string $issueType, string $statusId): string
    {
        return sprintf(
            'jira:transitions:%s:%s:%s:%s',
            $user->id,
            (string) $user->jira_project_key,
            $issueType,
            $statusId,
        );
    }

    /**
     * Return the cached transitions for the pair, fetching once from Jira on a miss.
     *
     * @return list<array{id: string, to_id: string, to_name: string}>
     */
    public static function remember(User $user, JiraClient $client, string $issueKey, string $issueType, string $statusId): array
    {
        return Cache::remember(
            self::key($user, $issueType, $statusId),
            self::TTL_SECONDS,
            static fn (): array => self::fetch($client, $issueKey),
        );
    }

    /**
     * Store the given transitions for the pair.
     *
     * @param  list<array{id: string, to_id: string, to_name: string}>  $transitions
     */
    public static function put(User $user, string $issueType, string $statusId, array $transitions): void
    {
        Cache::put(self::key($user, $issueType, $statusId), $transitions, self::TTL_SECONDS);
    }

    /**
     * Fetch and normalise the transitions available for an issue from Jira.
     *
     * @return list<array{id: string, to_id: string, to_name: string}>
     */
    public static function fetch(JiraClient $client, string $issueKey): array
    {
        return array_map(
            static fn (array $transition): array => [
                'id' => (string) ($transition['id'] ?? ''),
                'to_id' => (string) data_get($transition, 'to.id', ''),
                'to_name' => (string) data_get($transition, 'to.name', ''),
            ],
            $client->getTransitions($issueKey),
        );
    }
}
