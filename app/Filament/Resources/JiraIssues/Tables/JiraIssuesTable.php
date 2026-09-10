<?php

namespace App\Filament\Resources\JiraIssues\Tables;

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Filament\Resources\JiraIssues\Pages\ListConcerningTasks;
use App\Jobs\SyncJiraIssuesJob;
use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraClient;
use App\Services\Jira\JiraIssueActions;
use App\Services\Jira\JiraTransitionsCache;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class JiraIssuesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('jira_key')
                    ->label('Key')
                    ->url(fn (JiraIssue $record): string => $record->jira_url, shouldOpenInNewTab: true)
                    ->color('primary')
                    ->copyable()
                    ->copyableState(fn (JiraIssue $record): string => $record->jira_key)
                    ->copyMessage('Key copied')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('summary')
                    ->html()
                    ->formatStateUsing(fn (JiraIssue $record): string => self::boldBracketedText($record->summary))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('issue_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (JiraIssue $record): string => self::issueTypeColor($record->issue_type)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (JiraIssue $record): string => self::statusColor($record->status_category)),
                TextColumn::make('assignee_name')
                    ->label('Assignee')
                    ->placeholder('Unassigned'),
                TextColumn::make('priority')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sprints')
                    ->label('Sprint')
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->state(fn (JiraIssue $record): array => $record->sprints ?? [])
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('jira_updated_at')
                    ->label('Jira updated')
                    ->formatStateUsing(fn (JiraIssue $record): ?string => $record->jira_updated_at?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true))
                    ->tooltip(fn (JiraIssue $record): ?string => $record->jira_updated_at?->toDayDateTimeString())
                    ->sortable(),
                TextColumn::make('last_synced_at')
                    ->label('Last synced')
                    ->formatStateUsing(fn (JiraIssue $record): ?string => $record->last_synced_at?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true))
                    ->color(fn (JiraIssue $record): ?string => self::isStale($record) ? 'warning' : null)
                    ->description(fn (JiraIssue $record): ?string => self::isStale($record) ? 'Stale' : null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(fn (): array => self::distinctOptions('status')),
                SelectFilter::make('issue_type')
                    ->label('Type')
                    ->options(fn (): array => self::distinctOptions('issue_type')),
                SelectFilter::make('assignee_name')
                    ->label('Assignee')
                    ->options(fn (): array => self::distinctOptions('assignee_name')),
                SelectFilter::make('sprint')
                    ->label('Sprint')
                    ->options(fn (): array => self::sprintOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        return $query->where('sprints', 'like', '%'.json_encode($value).'%');
                    }),
            ])
            ->recordUrl(fn (JiraIssue $record): string => JiraIssueResource::getUrl('view', ['record' => $record]))
            ->defaultSort('jira_updated_at', 'desc')
            ->recordActions([
                self::updateStatusAssigneeAction(),
                self::starAction(),
                self::snoozeAction(),
                self::unsnoozeAction(),
                self::dismissAction(),
            ])
            ->toolbarActions([
                self::bulkStarAction(),
                self::bulkSnoozeAction(),
                self::bulkDismissAction(),
            ])
            ->headerActions([
                Action::make('sync')
                    ->label('Sync from Jira')
                    ->icon(Heroicon::ArrowPath)
                    ->disabled(fn (): bool => ! self::user()->hasJiraConnection())
                    ->tooltip(fn (): ?string => self::user()->hasJiraConnection() ? null : 'Connect Jira first')
                    ->action(function (): void {
                        SyncJiraIssuesJob::dispatch(self::user());

                        Notification::make()
                            ->success()
                            ->title('Sync queued')
                            ->send();
                    }),
            ])
            ->poll('30s');
    }

    /**
     * Escape the summary and wrap any bracketed segments (e.g. `[API]`) in bold,
     * keeping the brackets themselves.
     */
    private static function boldBracketedText(?string $summary): string
    {
        return preg_replace_callback(
            '/\[[^\]]*\]/',
            static fn (array $matches): string => '<strong>'.$matches[0].'</strong>',
            e((string) $summary),
        ) ?? e((string) $summary);
    }

    public static function issueTypeColor(?string $issueType): string
    {
        $issueType = strtolower((string) $issueType);

        $keywordColors = [
            'sub' => 'gray',
            'bug' => 'danger',
            'story' => 'success',
            'epic' => 'primary',
            'improvement' => 'warning',
            'feature' => 'warning',
            'task' => 'info',
        ];

        foreach ($keywordColors as $keyword => $color) {
            if (str_contains($issueType, $keyword)) {
                return $color;
            }
        }

        return 'gray';
    }

    /**
     * Modal action to change the status and/or assignee in one step, with an
     * optional dismiss toggle that clears the task once the update lands.
     */
    private static function updateStatusAssigneeAction(): Action
    {
        return Action::make('updateStatusAssignee')
            ->label('Update status / assignee')
            ->icon(Heroicon::PencilSquare)
            ->color('gray')
            ->iconButton()
            ->visible(fn (): bool => self::user()->hasJiraConnection())
            ->schema([
                Select::make('status_id')
                    ->label('Status')
                    ->options(fn (JiraIssue $record): array => self::statusOptions($record))
                    ->default(fn (JiraIssue $record): string => $record->status_id)
                    ->selectablePlaceholder(false)
                    ->required(),
                Select::make('assignee_account_id')
                    ->label('Assignee')
                    ->placeholder('Unassigned')
                    ->default(fn (JiraIssue $record): ?string => $record->assignee_account_id)
                    ->options(fn (JiraIssue $record): array => self::assigneeOptions($record))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchAssignees($search))
                    ->getOptionLabelUsing(fn (JiraIssue $record, mixed $value): ?string => self::assigneeOptionLabel($record, $value)),
                Toggle::make('dismiss')
                    ->label('Dismiss this task')
                    ->default(false),
            ])
            ->action(function (JiraIssue $record, array $data): void {
                $actions = JiraIssueActions::forUser(self::user());
                $originalStatusId = $record->status_id;
                $originalAssignee = $record->assignee_account_id;

                try {
                    if ((string) $data['status_id'] !== $originalStatusId) {
                        $actions->transition($record, (string) $data['status_id']);
                    }

                    $accountId = $data['assignee_account_id'] ?? null;

                    if ($accountId !== $originalAssignee) {
                        $actions->assign($record, filled($accountId) ? (string) $accountId : null);
                    }
                } catch (JiraApiException $exception) {
                    Notification::make()->danger()->title('Update failed')->body($exception->getMessage())->send();

                    return;
                }

                if ($data['dismiss'] ?? false) {
                    self::dismissRecord($record);
                }

                Notification::make()->success()->title('Task updated')->send();
            });
    }

    /**
     * The current status plus any reachable transition targets from cache.
     *
     * @return array<string, string>
     */
    private static function statusOptions(JiraIssue $record): array
    {
        $options = [$record->status_id => $record->status];

        $user = self::user();

        if (! $user->hasJiraConnection()) {
            return $options;
        }

        try {
            $transitions = JiraTransitionsCache::remember(
                $user,
                JiraClient::forUser($user),
                $record->jira_key,
                $record->issue_type,
                $record->status_id,
            );
        } catch (JiraApiException) {
            return $options;
        }

        foreach ($transitions as $transition) {
            $options[$transition['to_id']] = $transition['to_name'];
        }

        return $options;
    }

    /**
     * Render-time assignee options: the current user and current assignee.
     *
     * @return array<string, string>
     */
    private static function assigneeOptions(JiraIssue $record): array
    {
        $user = self::user();
        $options = [];

        if (filled($user->jira_account_id)) {
            $options[$user->jira_account_id] = 'Me ('.$user->name.')';
        }

        if (filled($record->assignee_account_id)) {
            $options[$record->assignee_account_id] = $record->assignee_name ?? $record->assignee_account_id;
        }

        return $options;
    }

    /**
     * Search Jira's assignable users for the current project (cached per query).
     *
     * @return array<string, string>
     */
    private static function searchAssignees(string $search): array
    {
        $user = self::user();

        if (! $user->hasJiraConnection() || blank($search)) {
            return [];
        }

        $project = (string) $user->jira_project_key;
        $key = "jira:assignable:{$user->id}:{$project}:".md5($search);

        $results = Cache::remember(
            $key,
            300,
            fn (): array => JiraClient::forUser($user)->searchAssignableUsers($project, $search),
        );

        return collect($results)
            ->pluck('displayName', 'accountId')
            ->all();
    }

    /**
     * Resolve the display label for a selected assignee value.
     */
    private static function assigneeOptionLabel(JiraIssue $record, mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $user = self::user();

        if ($value === $user->jira_account_id) {
            return 'Me ('.$user->name.')';
        }

        if ($value === $record->assignee_account_id) {
            return $record->assignee_name ?? (string) $value;
        }

        return $record->assignee_name ?? (string) $value;
    }

    /**
     * Map a Jira status category to a badge color.
     */
    private static function statusColor(?string $statusCategory): string
    {
        return match ($statusCategory) {
            'Done' => 'success',
            'In Progress' => 'info',
            default => 'gray',
        };
    }

    /**
     * Dismiss a task until Jira reports newer activity. A dismissed task is also
     * unstarred so it does not linger on the concerning list via the important flag.
     */
    private static function dismissRecord(JiraIssue $record): void
    {
        $record->update([
            'dismissed_at' => Carbon::now(),
            'is_important' => false,
        ]);
    }

    /**
     * Toggle the important (starred) flag. Available on both task lists.
     */
    private static function starAction(): Action
    {
        return Action::make('toggleImportant')
            ->label(fn (JiraIssue $record): string => $record->is_important ? 'Unstar' : 'Star')
            ->icon(fn (JiraIssue $record): Heroicon => $record->is_important ? Heroicon::Star : Heroicon::OutlinedStar)
            ->color(fn (JiraIssue $record): string => $record->is_important ? 'warning' : 'gray')
            ->iconButton()
            ->action(fn (JiraIssue $record) => $record->update(['is_important' => ! $record->is_important]));
    }

    /**
     * Snooze the task for a chosen window. Only on the concerning list.
     */
    private static function snoozeAction(): Action
    {
        return Action::make('snooze')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->iconButton()
            ->visible(fn (HasTable $livewire): bool => $livewire instanceof ListConcerningTasks)
            ->schema([
                Select::make('duration')
                    ->label('Snooze until')
                    ->options(self::snoozeOptions())
                    ->default('tomorrow')
                    ->required(),
            ])
            ->action(function (JiraIssue $record, array $data): void {
                $record->update(['snoozed_until' => self::snoozeUntil($data['duration'])]);
            });
    }

    /**
     * Clear an active snooze. Shown wherever a snoozed task is visible; since a
     * snoozed task is hidden from the concerning list, this surfaces on the full
     * task list so the snooze can be lifted.
     */
    private static function unsnoozeAction(): Action
    {
        return Action::make('unsnooze')
            ->label('Un-snooze')
            ->icon(Heroicon::OutlinedBellSlash)
            ->color('gray')
            ->iconButton()
            ->visible(fn (JiraIssue $record): bool => filled($record->snoozed_until))
            ->action(fn (JiraIssue $record) => $record->update(['snoozed_until' => null]));
    }

    /**
     * Dismiss the task until Jira reports newer activity. Only on the
     * concerning list.
     */
    private static function dismissAction(): Action
    {
        return Action::make('dismiss')
            ->icon(Heroicon::OutlinedCheck)
            ->color('gray')
            ->iconButton()
            ->visible(fn (HasTable $livewire): bool => $livewire instanceof ListConcerningTasks)
            ->action(fn (JiraIssue $record) => self::dismissRecord($record));
    }

    /**
     * Bulk star the selected tasks. Only on the concerning list.
     */
    private static function bulkStarAction(): BulkAction
    {
        return BulkAction::make('bulkStar')
            ->label('Star')
            ->icon(Heroicon::Star)
            ->color('warning')
            ->visible(fn (HasTable $livewire): bool => $livewire instanceof ListConcerningTasks)
            ->action(fn (Collection $records) => $records->each->update(['is_important' => true]))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Bulk snooze the selected tasks for a chosen window. Only on the
     * concerning list.
     */
    private static function bulkSnoozeAction(): BulkAction
    {
        return BulkAction::make('bulkSnooze')
            ->label('Snooze')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->visible(fn (HasTable $livewire): bool => $livewire instanceof ListConcerningTasks)
            ->schema([
                Select::make('duration')
                    ->label('Snooze until')
                    ->options(self::snoozeOptions())
                    ->default('tomorrow')
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $until = self::snoozeUntil($data['duration']);

                $records->each->update(['snoozed_until' => $until]);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Bulk dismiss the selected tasks. Only on the concerning list.
     */
    private static function bulkDismissAction(): BulkAction
    {
        return BulkAction::make('bulkDismiss')
            ->label('Dismiss')
            ->icon(Heroicon::OutlinedCheck)
            ->color('gray')
            ->visible(fn (HasTable $livewire): bool => $livewire instanceof ListConcerningTasks)
            ->action(fn (Collection $records) => $records->each(fn (JiraIssue $record) => self::dismissRecord($record)))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * The available snooze windows, keyed by token.
     *
     * @return array<string, string>
     */
    private static function snoozeOptions(): array
    {
        return [
            'tomorrow' => 'Tomorrow',
            '2_days' => '2 days',
            'next_week' => 'Next week',
        ];
    }

    /**
     * Resolve a snooze token into midnight (00:00:00) of the target day.
     */
    private static function snoozeUntil(string $duration): Carbon
    {
        return match ($duration) {
            'tomorrow' => Carbon::tomorrow(),
            '2_days' => Carbon::now()->addDays(2)->startOfDay(),
            'next_week' => Carbon::now()->addWeek()->startOfDay(),
            default => Carbon::now()->startOfDay(),
        };
    }

    /**
     * Build filter options from the current user's own issues.
     *
     * @return array<string, string>
     */
    private static function distinctOptions(string $column): array
    {
        return JiraIssue::query()
            ->where('user_id', auth()->id())
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }

    /**
     * Build the distinct sprint filter options from the current user's issues.
     *
     * @return array<string, string>
     */
    private static function sprintOptions(): array
    {
        return JiraIssue::query()
            ->where('user_id', auth()->id())
            ->whereNotNull('sprints')
            ->pluck('sprints')
            ->flatMap(fn (mixed $sprints): array => is_array($sprints) ? $sprints : [])
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $name): array => [$name => $name])
            ->all();
    }

    /**
     * A row is stale when it was last synced before the user's most recent sync.
     */
    private static function isStale(JiraIssue $record): bool
    {
        $lastSyncedAt = self::user()->jira_last_synced_at;

        return filled($lastSyncedAt) && $record->last_synced_at->lt($lastSyncedAt);
    }

    private static function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
