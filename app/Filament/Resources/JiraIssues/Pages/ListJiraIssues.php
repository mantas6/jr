<?php

namespace App\Filament\Resources\JiraIssues\Pages;

use App\Filament\Pages\JiraConnection;
use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListJiraIssues extends ListRecords
{
    protected static string $resource = JiraIssueResource::class;

    /**
     * Render the connection / last-sync status line above the table.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $user = $this->currentUser();

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

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
