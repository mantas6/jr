<?php

namespace App\Filament\Resources\JiraIssues\Pages;

use App\Filament\Resources\JiraIssues\Concerns\ShowsSyncStatusSubheading;
use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraIssueActions;
use App\Services\Jira\JiraIssueReferenceParser;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListConcerningTasks extends ListRecords
{
    use ShowsSyncStatusSubheading;

    protected static string $resource = JiraIssueResource::class;

    protected static ?string $title = 'Concerning Tasks';

    /**
     * Split the concerning list into active, unread (active and not viewed
     * since Jira's last update) and snoozed tasks, each with a count badge.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $activeQuery = $this->activeTabQuery(...);
        $unreadQuery = $this->unreadTabQuery(...);
        $snoozedQuery = $this->snoozedTabQuery(...);

        $unreadCount = $this->tabCount($unreadQuery);

        return [
            'active' => Tab::make('Active')
                ->modifyQueryUsing($activeQuery)
                ->badge($this->tabCount($activeQuery)),
            'unread' => Tab::make('Unread')
                ->modifyQueryUsing($unreadQuery)
                ->badge($unreadCount)
                ->badgeColor($unreadCount > 0 ? 'danger' : null),
            'snoozed' => Tab::make('Snoozed')
                ->modifyQueryUsing($snoozedQuery)
                ->badge($this->tabCount($snoozedQuery)),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'active';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->addTasksAction(),
        ];
    }

    /**
     * Restrict the table to tasks that currently need the user's attention.
     *
     * The base query stays snooze-agnostic; the "Active", "Unread" and
     * "Snoozed" tabs narrow it further.
     *
     * @return Builder<JiraIssue>
     */
    protected function getTableQuery(): Builder
    {
        return JiraIssueResource::getEloquentQuery()->concerning($this->currentUser());
    }

    /**
     * Count the page's base query after applying a tab's query modifier. Tabs
     * are built once per request (see `getCachedTabs()`), so each count runs once.
     *
     * @param  Closure(Builder<JiraIssue>): Builder<JiraIssue>  $modifyQuery
     */
    private function tabCount(Closure $modifyQuery): int
    {
        return $modifyQuery($this->getTableQuery())->count();
    }

    /**
     * Concerning tasks that are not currently snoozed (an expired snooze counts
     * as active).
     *
     * @param  Builder<JiraIssue>  $query
     * @return Builder<JiraIssue>
     */
    private function activeTabQuery(Builder $query): Builder
    {
        return $query->notSnoozed();
    }

    /**
     * Active tasks not viewed since their last Jira update.
     *
     * @param  Builder<JiraIssue>  $query
     * @return Builder<JiraIssue>
     */
    private function unreadTabQuery(Builder $query): Builder
    {
        return $query->notSnoozed()->unread();
    }

    /**
     * Concerning tasks with a snooze still in the future.
     *
     * @param  Builder<JiraIssue>  $query
     * @return Builder<JiraIssue>
     */
    private function snoozedTabQuery(Builder $query): Builder
    {
        return $query->snoozed();
    }

    /**
     * Pin one or more tasks to the concerning list from pasted Jira links or keys.
     */
    private function addTasksAction(): Action
    {
        return Action::make('addTasks')
            ->label('Add tasks')
            ->icon(Heroicon::OutlinedPlus)
            ->disabled(fn (): bool => !$this->currentUser()->hasJiraConnection())
            ->schema([
                Textarea::make('tasks')
                    ->label('Tasks')
                    ->required()
                    ->rows(8)
                    ->helperText('Paste Jira links or task keys, one per line or separated by spaces/commas. Names after the key are ignored.'),
            ])
            ->action(function (array $data, Action $action): void {
                $user = $this->currentUser();

                $keys = JiraIssueReferenceParser::parse((string) $data['tasks'], $user->jira_project_key);

                if ($keys === []) {
                    Notification::make()
                        ->warning()
                        ->title('No task keys found')
                        ->body('Paste Jira links (e.g. .../browse/PROJ-123) or keys like PROJ-123.')
                        ->send();

                    $action->halt();
                }

                $result = JiraIssueActions::forUser($user)->addConcerning($keys);

                $this->notifyAddResult($result);
            });
    }

    /**
     * Build the success (and, when relevant, failure) notifications for the batch.
     *
     * @param  array{added: list<string>, existing: list<string>, failed: array<string, string>}  $result
     */
    private function notifyAddResult(array $result): void
    {
        $handled = [...$result['added'], ...$result['existing']];

        if ($handled !== []) {
            Notification::make()
                ->success()
                ->title('Added '.count($handled).' task(s)')
                ->body(implode(', ', $handled))
                ->send();
        }

        if ($result['failed'] !== []) {
            $lines = [];

            foreach ($result['failed'] as $key => $reason) {
                $lines[] = "{$key}: {$reason}";
            }

            Notification::make()
                ->danger()
                ->title('Could not add '.count($result['failed']).' task(s)')
                ->body(implode("\n", $lines))
                ->send();
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
