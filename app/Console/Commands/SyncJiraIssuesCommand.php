<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncJiraIssuesJob;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('jira:sync {user? : User ID} {--force : Force a full project resync}')]
#[Description('Queue a Jira issue sync for a single user or every connected user.')]
class SyncJiraIssuesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = $this->argument('user');

        if ($userId !== null) {
            return $this->dispatchForUser((int) $userId);
        }

        return $this->dispatchForAllConnectedUsers();
    }

    /**
     * Dispatch a sync for a single user, failing when the user does not exist.
     */
    private function dispatchForUser(int $userId): int
    {
        $user = User::find($userId);

        if ($user === null) {
            $this->error("User [{$userId}] not found.");

            return self::FAILURE;
        }

        SyncJiraIssuesJob::dispatch($user, force: (bool) $this->option('force'));

        $this->info("Queued Jira sync for user [{$userId}].");

        return self::SUCCESS;
    }

    /**
     * Dispatch a sync for every user with an established Jira connection.
     */
    private function dispatchForAllConnectedUsers(): int
    {
        $count = 0;
        $force = (bool) $this->option('force');

        User::query()
            ->whereNotNull('jira_connected_at')
            ->each(function (User $user) use (&$count, $force): void {
                SyncJiraIssuesJob::dispatch($user, force: $force);
                $count++;
            });

        $this->info("Queued Jira sync for {$count} connected user(s).");

        return self::SUCCESS;
    }
}
