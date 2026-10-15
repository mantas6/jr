<?php

namespace App\Filament\Resources\JiraReleases\RelationManagers;

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * The release's tasks, shown with the task resource's table (columns,
 * filters and actions) and linking each row to the task view page.
 */
class IssuesRelationManager extends RelationManager
{
    protected static string $relationship = 'issues';

    protected static ?string $relatedResource = JiraIssueResource::class;

    protected static ?string $title = 'Tasks';
}
