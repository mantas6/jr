<?php

namespace App\Filament\Resources\JiraReleases;

use App\Filament\Resources\JiraReleases\Pages\ListJiraReleases;
use App\Filament\Resources\JiraReleases\Pages\ViewJiraRelease;
use App\Filament\Resources\JiraReleases\RelationManagers\IssuesRelationManager;
use App\Filament\Resources\JiraReleases\Schemas\JiraReleaseInfolist;
use App\Filament\Resources\JiraReleases\Tables\JiraReleasesTable;
use App\Models\JiraRelease;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends resource<JiraRelease>
 */
class JiraReleaseResource extends Resource
{
    protected static ?string $model = JiraRelease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Release';

    protected static ?string $pluralModelLabel = 'Releases';

    protected static ?string $navigationLabel = 'Releases';

    /**
     * Sorted after the "Tasks" item registered by the Jira issue resource.
     */
    protected static ?int $navigationSort = 12;

    public static function infolist(Schema $schema): Schema
    {
        return JiraReleaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JiraReleasesTable::configure($table);
    }

    /**
     * Scope the resource to the currently authenticated user's releases, with
     * their total and done task counts.
     *
     * @return Builder<JiraRelease>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->withIssueProgress();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<int, class-string<RelationManager>>
     */
    public static function getRelations(): array
    {
        return [
            IssuesRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListJiraReleases::route('/'),
            'view' => ViewJiraRelease::route('/{record}'),
        ];
    }
}
