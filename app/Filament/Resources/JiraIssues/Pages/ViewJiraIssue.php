<?php

namespace App\Filament\Resources\JiraIssues\Pages;

use App\Filament\Resources\JiraIssues\JiraIssueResource;
use App\Filament\Resources\JiraIssues\Tables\JiraIssuesTable;
use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraClient;
use App\Services\Jira\JiraIssueContentMapper;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

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
     * The issue's comments, ordered newest to oldest.
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

            return $this->issueContent = JiraIssueContentMapper::map($payload, $user->jira_site_url);
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
            JiraIssuesTable::updateStatusAssigneeAction(),
            $this->toggleImportantAction(),
            $this->snoozeAction(),
            $this->unsnoozeAction(),
            $this->dismissAction(),
        ];
    }

    /**
     * Toggle the important (starred) flag on the issue.
     */
    private function toggleImportantAction(): Action
    {
        return Action::make('toggleImportant')
            ->label(fn (): string => $this->currentRecord()->is_important ? 'Unstar' : 'Star')
            ->icon(fn (): Heroicon => $this->currentRecord()->is_important ? Heroicon::Star : Heroicon::OutlinedStar)
            ->color(fn (): string => $this->currentRecord()->is_important ? 'warning' : 'gray')
            ->action(function (): void {
                $record = $this->currentRecord();
                $record->update(['is_important' => ! $record->is_important]);

                Notification::make()->success()->title($record->is_important ? 'Marked important' : 'Unstarred')->send();
            });
    }

    /**
     * Snooze the issue for a chosen window.
     */
    private function snoozeAction(): Action
    {
        return Action::make('snooze')
            ->label('Snooze')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->schema([
                Select::make('duration')
                    ->label('Snooze until')
                    ->options([
                        'tomorrow' => 'Tomorrow',
                        '2_days' => '2 days',
                        'next_week' => 'Next week',
                    ])
                    ->default('tomorrow')
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->currentRecord()->update(['snoozed_until' => $this->snoozeUntil($data['duration'])]);

                Notification::make()->success()->title('Snoozed')->send();
            });
    }

    /**
     * Clear an active snooze on the issue.
     */
    private function unsnoozeAction(): Action
    {
        return Action::make('unsnooze')
            ->label('Un-snooze')
            ->icon(Heroicon::OutlinedBellSlash)
            ->color('gray')
            ->visible(fn (): bool => filled($this->currentRecord()->snoozed_until))
            ->action(function (): void {
                $this->currentRecord()->update(['snoozed_until' => null]);

                Notification::make()->success()->title('Un-snoozed')->send();
            });
    }

    /**
     * Dismiss the issue until Jira reports newer activity.
     */
    private function dismissAction(): Action
    {
        return Action::make('dismiss')
            ->label('Dismiss')
            ->icon(Heroicon::OutlinedCheck)
            ->color('gray')
            ->action(function (): void {
                $this->currentRecord()->update(['dismissed_at' => now(), 'is_important' => false]);

                Notification::make()->success()->title('Dismissed')->send();
            });
    }

    /**
     * Resolve a snooze token into midnight (00:00:00) of the target day.
     */
    private function snoozeUntil(string $duration): CarbonInterface
    {
        return match ($duration) {
            'tomorrow' => Carbon::tomorrow(),
            '2_days' => Carbon::now()->addDays(2)->startOfDay(),
            'next_week' => Carbon::now()->addWeek()->startOfDay(),
            default => Carbon::now()->startOfDay(),
        };
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
