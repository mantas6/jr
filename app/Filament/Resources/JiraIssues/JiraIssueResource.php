<?php

namespace App\Filament\Resources\JiraIssues;

use App\Filament\Resources\JiraIssues\Pages\ListConcerningTasks;
use App\Filament\Resources\JiraIssues\Pages\ListJiraIssues;
use App\Filament\Resources\JiraIssues\Pages\ViewJiraIssue;
use App\Filament\Resources\JiraIssues\Schemas\JiraIssueInfolist;
use App\Filament\Resources\JiraIssues\Tables\JiraIssuesTable;
use App\Models\JiraIssue;
use App\Models\User;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

use function Filament\Support\original_request;

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

    public static function infolist(Schema $schema): Schema
    {
        return JiraIssueInfolist::configure($schema);
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
            'concerning' => ListConcerningTasks::route('/concerning'),
            'view' => ViewJiraIssue::route('/{record}'),
        ];
    }

    /**
     * Register two sibling navigation items: the full task list and the
     * attention-driven "Concerning Tasks" list (with a live count badge).
     *
     * @return array<int, NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        $base = static::getRouteBaseName();
        $concerningCount = static::concerningCount();

        return [
            NavigationItem::make('Concerning Tasks')
                ->icon(Heroicon::OutlinedBellAlert)
                ->sort(10)
                ->badge($concerningCount > 0 ? (string) $concerningCount : null, color: 'warning')
                ->isActiveWhen(fn (): bool => original_request()->routeIs($base.'.concerning'))
                ->url(fn (): string => ListConcerningTasks::getUrl()),
            NavigationItem::make('Tasks')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->sort(11)
                ->isActiveWhen(fn (): bool => original_request()->routeIs($base.'.index', $base.'.view'))
                ->url(fn (): string => ListJiraIssues::getUrl()),
        ];
    }

    /**
     * Count the current user's tasks that currently need attention.
     */
    protected static function concerningCount(): int
    {
        $user = auth()->user();

        if (!$user instanceof User) {
            return 0;
        }

        return JiraIssue::query()
            ->where('user_id', $user->id)
            ->concerningFor($user)
            ->count();
    }
}
