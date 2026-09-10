<?php

namespace App\Filament\Resources\JiraIssues\Pages;

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraClient;
use App\Services\Jira\JiraIssueActions;
use App\Services\Jira\JiraIssueContentMapper;
use App\Services\Jira\JiraTransitionsCache;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class ViewJiraIssue extends ViewRecord
{
    protected static string $resource = JiraIssueResource::class;

    /**
     * The issue's description and comments, fetched live from Jira.
     *
     * @var array{description: string|null, comments: list<array{author: string, created: CarbonInterface|null, body: string}>}|null
     */
    private ?array $issueContent = null;

    /**
     * The rendered description HTML, or null when the issue has none.
     */
    public function descriptionHtml(): ?string
    {
        return $this->issueContent()['description'];
    }

    /**
     * The issue's comments, ordered oldest to newest.
     *
     * @return list<array{author: string, created: CarbonInterface|null, body: string}>
     */
    public function comments(): array
    {
        return $this->issueContent()['comments'];
    }

    /**
     * Fetch (and memoize) the issue's description and comments from Jira so a
     * single API call feeds both the description and comments sections.
     *
     * @return array{description: string|null, comments: list<array{author: string, created: CarbonInterface|null, body: string}>}
     */
    private function issueContent(): array
    {
        if ($this->issueContent !== null) {
            return $this->issueContent;
        }

        $user = $this->currentUser();

        if (! $user->hasJiraConnection()) {
            return $this->issueContent = ['description' => null, 'comments' => []];
        }

        try {
            $payload = JiraClient::forUser($user)->getIssueContent($this->currentRecord()->jira_key);

            return $this->issueContent = JiraIssueContentMapper::map($payload);
        } catch (JiraApiException) {
            return $this->issueContent = ['description' => null, 'comments' => []];
        }
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->changeStatusAction(),
            $this->reassignAction(),
        ];
    }

    /**
     * Transition the issue to another status in Jira.
     */
    private function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Change status')
            ->icon(Heroicon::ArrowsRightLeft)
            ->visible(fn (): bool => $this->currentUser()->hasJiraConnection())
            ->schema([
                Select::make('status_id')
                    ->label('Status')
                    ->options(fn (): array => $this->statusOptions())
                    ->default(fn (): string => $this->currentRecord()->status_id)
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                try {
                    JiraIssueActions::forUser($this->currentUser())
                        ->transition($this->currentRecord(), (string) $data['status_id']);

                    $this->currentRecord()->refresh();

                    Notification::make()->success()->title('Status updated')->send();
                } catch (JiraApiException $exception) {
                    Notification::make()->danger()->title('Could not update status')->body($exception->getMessage())->send();
                }
            });
    }

    /**
     * Assign (or unassign) the issue in Jira.
     */
    private function reassignAction(): Action
    {
        return Action::make('reassign')
            ->label('Reassign')
            ->icon(Heroicon::UserCircle)
            ->visible(fn (): bool => $this->currentUser()->hasJiraConnection())
            ->schema([
                Select::make('assignee_account_id')
                    ->label('Assignee')
                    ->placeholder('Unassigned')
                    ->default(fn (): ?string => $this->currentRecord()->assignee_account_id)
                    ->options(fn (): array => $this->assigneeOptions())
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->searchAssignees($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => $this->assigneeOptionLabel($value)),
            ])
            ->action(function (array $data): void {
                $accountId = $data['assignee_account_id'] ?? null;

                try {
                    JiraIssueActions::forUser($this->currentUser())
                        ->assign($this->currentRecord(), filled($accountId) ? (string) $accountId : null);

                    $this->currentRecord()->refresh();

                    Notification::make()->success()->title('Assignee updated')->send();
                } catch (JiraApiException $exception) {
                    Notification::make()->danger()->title('Could not reassign')->body($exception->getMessage())->send();
                }
            });
    }

    /**
     * The current status plus any reachable transition targets.
     *
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $record = $this->currentRecord();
        $options = [$record->status_id => $record->status];

        $user = $this->currentUser();

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
    private function assigneeOptions(): array
    {
        $user = $this->currentUser();
        $record = $this->currentRecord();
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
     * Search Jira's assignable users for the current project.
     *
     * @return array<string, string>
     */
    private function searchAssignees(string $search): array
    {
        $user = $this->currentUser();

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
    private function assigneeOptionLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $user = $this->currentUser();
        $record = $this->currentRecord();

        if ($value === $user->jira_account_id) {
            return 'Me ('.$user->name.')';
        }

        if ($value === $record->assignee_account_id) {
            return $record->assignee_name;
        }

        return $record->assignee_name ?? (string) $value;
    }

    private function currentRecord(): JiraIssue
    {
        /** @var JiraIssue $record */
        $record = $this->record;

        return $record;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
