<?php

namespace App\Filament\Resources\JiraIssues;

use App\Filament\Resources\JiraIssues\Pages\ListJiraIssues;
use App\Filament\Resources\JiraIssues\Tables\JiraIssuesTable;
use App\Models\JiraIssue;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends resource<JiraIssue>
 */
class JiraIssueResource extends Resource
{
    protected static ?string $model = JiraIssue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'Task';

    protected static ?string $pluralModelLabel = 'Tasks';

    protected static ?string $navigationLabel = 'Tasks';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return JiraIssuesTable::configure($table);
    }

    /**
     * Scope the resource to the currently authenticated user's issues.
     *
     * @return Builder<JiraIssue>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListJiraIssues::route('/'),
        ];
    }
}
