<?php

namespace App\Filament\Resources\JiraIssues\Pages;

use App\Filament\Resources\JiraIssues\Concerns\ShowsSyncStatusSubheading;
use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Models\JiraIssue;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListConcerningTasks extends ListRecords
{
    use ShowsSyncStatusSubheading;

    protected static string $resource = JiraIssueResource::class;

    protected static ?string $title = 'Concerning Tasks';

    /**
     * Restrict the table to tasks that currently need the user's attention.
     *
     * @return Builder<JiraIssue>|null
     */
    protected function getTableQuery(): ?Builder
    {
        return parent::getTableQuery()?->concerningFor($this->currentUser());
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
