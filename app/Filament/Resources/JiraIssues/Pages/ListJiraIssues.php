<?php

namespace App\Filament\Resources\JiraIssues\Pages;

use App\Filament\Resources\JiraIssues\Concerns\ShowsSyncStatusSubheading;
use App\Filament\Resources\JiraIssues\JiraIssueResource;
use Filament\Resources\Pages\ListRecords;

class ListJiraIssues extends ListRecords
{
    use ShowsSyncStatusSubheading;

    protected static string $resource = JiraIssueResource::class;
}
