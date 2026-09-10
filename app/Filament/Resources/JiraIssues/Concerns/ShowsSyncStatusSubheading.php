<?php

namespace App\Filament\Resources\JiraIssues\Concerns;

use App\Filament\Pages\JiraConnection;
use App\Models\User;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Renders the Jira connection / last-sync status line above a task list.
 */
trait ShowsSyncStatusSubheading
{
    public function getSubheading(): string|Htmlable|null
    {
        $user = $this->syncStatusUser();

        if (! $user->hasJiraConnection()) {
            return new HtmlString(
                'Jira not connected — <a href="'.e(JiraConnection::getUrl()).'" class="fi-link">Connect</a>'
            );
        }

        if (filled($user->jira_last_sync_error)) {
            return new HtmlString(
                '<span class="text-danger-600 dark:text-danger-400">Last sync failed: '.e($user->jira_last_sync_error).'</span>'
            );
        }

        if (filled($user->jira_last_synced_at)) {
            return new HtmlString('Last synced '.e($user->jira_last_synced_at->diffForHumans()));
        }

        return 'Not synced yet';
    }

    private function syncStatusUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
