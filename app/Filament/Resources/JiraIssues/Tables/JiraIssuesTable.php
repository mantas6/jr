<?php

namespace App\Filament\Resources\JiraIssues\Tables;

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Jobs\SyncJiraIssuesJob;
use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraClient;
use App\Services\Jira\JiraIssueActions;
use App\Services\Jira\JiraTransitionsCache;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                    ->sortable()
                    ->searchable(),
                TextColumn::make('copy_key')
                    ->label('')
                    ->state('')
                    ->icon(Heroicon::ClipboardDocument)
                    ->tooltip('Copy key')
                    ->copyable()
                    ->copyableState(fn (JiraIssue $record): string => $record->jira_key)
                    ->copyMessage('Key copied'),
                TextColumn::make('summary')
                    ->html()
                    ->formatStateUsing(fn (JiraIssue $record): string => self::boldBracketedText($record->summary))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('issue_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (JiraIssue $record): string => self::issueTypeColor($record->issue_type)),
                self::statusColumn(),
                self::assigneeColumn(),
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
     * Inline status dropdown driven by the cached Jira transitions.
     */
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

    private static function statusColumn(): SelectColumn
    {
        return SelectColumn::make('status_id')
            ->label('Status')
            ->disabled(fn (): bool => ! self::user()->hasJiraConnection())
            ->options(function (JiraIssue $record): array {
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
            })
            ->updateStateUsing(function (JiraIssue $record, mixed $state): mixed {
                if ($state === $record->status_id) {
                    return $state;
                }

                try {
                    return JiraIssueActions::forUser(self::user())
                        ->transition($record, (string) $state)
                        ->status_id;
                } catch (JiraApiException $exception) {
                    return ['error' => $exception->getMessage()];
                }
            });
    }

    /**
     * Inline assignee dropdown. Render-time options are local only; searching
     * hits Jira's assignable-users endpoint (cached per user/project/query).
     */
    private static function assigneeColumn(): SelectColumn
    {
        return SelectColumn::make('assignee_account_id')
            ->label('Assignee')
            ->selectablePlaceholder()
            ->placeholder('Unassigned')
            ->disabled(fn (): bool => ! self::user()->hasJiraConnection())
            ->options(function (JiraIssue $record): array {
                $user = self::user();
                $options = [];

                if (filled($user->jira_account_id)) {
                    $options[$user->jira_account_id] = 'Me ('.$user->name.')';
                }

                if (filled($record->assignee_account_id)) {
                    $options[$record->assignee_account_id] = $record->assignee_name ?? $record->assignee_account_id;
                }

                return $options;
            })
            ->searchableOptions()
            ->getOptionsSearchResultsUsing(function (string $search): array {
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
            })
            ->getOptionLabelUsing(function (JiraIssue $record, mixed $value): ?string {
                if (blank($value)) {
                    return null;
                }

                $user = self::user();

                if ($value === $user->jira_account_id) {
                    return 'Me ('.$user->name.')';
                }

                if ($value === $record->assignee_account_id) {
                    return $record->assignee_name;
                }

                return $record->assignee_name ?? (string) $value;
            })
            ->updateStateUsing(function (JiraIssue $record, mixed $state): mixed {
                try {
                    return JiraIssueActions::forUser(self::user())
                        ->assign($record, filled($state) ? (string) $state : null)
                        ->assignee_account_id;
                } catch (JiraApiException $exception) {
                    return ['error' => $exception->getMessage()];
                }
            });
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
