<?php

namespace App\Filament\Resources\JiraIssues\Schemas;

use App\Filament\Resources\JiraIssues\Pages\ViewJiraIssue;
use App\Filament\Resources\JiraIssues\Tables\JiraIssuesTable;
use App\Models\JiraIssue;
use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraClient;
use App\Services\Jira\JiraPriority;
use App\Services\Jira\JiraPullRequestState;
use App\Services\Jira\JiraStatus;
use App\Services\Jira\JiraTextToAdf;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Js;

class JiraIssueInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Task')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('jira_key')
                            ->label('Key')
                            ->url(fn (JiraIssue $record): string => $record->jira_url, shouldOpenInNewTab: true)
                            ->color('primary'),
                        TextEntry::make('issue_type')
                            ->label('Type')
                            ->badge()
                            ->color(fn (JiraIssue $record): string => JiraIssuesTable::issueTypeColor($record->issue_type)),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (JiraIssue $record): string => JiraStatus::color($record->status, $record->status_category)),
                        TextEntry::make('priority')
                            ->badge()
                            ->color(fn (JiraIssue $record): string => JiraPriority::color($record->priority))
                            ->placeholder('—'),
                        TextEntry::make('assignee_name')
                            ->label('Assignee')
                            ->placeholder('Unassigned'),
                        TextEntry::make('reporter_name')
                            ->label('Reporter')
                            ->placeholder('—'),
                        TextEntry::make('sprints')
                            ->label('Sprint')
                            ->badge()
                            ->color('info')
                            ->placeholder('—')
                            ->state(fn (JiraIssue $record): array => $record->sprints ?? []),
                        TextEntry::make('pr_state')
                            ->label('Pull requests')
                            ->state(fn (ViewJiraIssue $livewire): ?string => $livewire->pullRequestSummary())
                            ->badge()
                            ->color(fn (JiraIssue $record): string => $record->pr_state?->color() ?? 'gray')
                            ->placeholder('None'),
                        TextEntry::make('summary')
                            ->columnSpanFull(),
                    ]),
                Section::make('Activity')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('jira_created_at')
                            ->label('Created')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('jira_updated_at')
                            ->label('Updated')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('last_synced_at')
                            ->label('Last synced')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('original_estimate_seconds')
                            ->label('Original estimate')
                            ->state(fn (JiraIssue $record): ?string => $record->formattedOriginalEstimate())
                            ->placeholder('—'),
                    ]),
                Group::make()
                    ->key('jiraContent')
                    ->columnSpanFull()
                    ->schema(
                        Schema::make()
                            ->components([
                                Section::make('Subtasks')
                                    ->columnSpanFull()
                                    ->visible(fn (ViewJiraIssue $livewire): bool => $livewire->subtasks() !== [])
                                    ->schema([
                                        RepeatableEntry::make('subtasks')
                                            ->hiddenLabel()
                                            ->state(fn (ViewJiraIssue $livewire): array => $livewire->subtasks())
                                            ->table([
                                                TableColumn::make('Key'),
                                                TableColumn::make('Summary'),
                                                TableColumn::make('Status'),
                                                TableColumn::make('Priority'),
                                            ])
                                            ->schema([
                                                TextEntry::make('key')
                                                    ->url(fn (Get $get): ?string => $get('url'), shouldOpenInNewTab: true)
                                                    ->color('primary'),
                                                TextEntry::make('summary'),
                                                TextEntry::make('status')
                                                    ->badge()
                                                    ->color(fn (?string $state, Get $get): string => JiraStatus::color($state, $get('status_category')))
                                                    ->placeholder('—'),
                                                TextEntry::make('priority')
                                                    ->badge()
                                                    ->color(fn (?string $state): string => JiraPriority::color($state))
                                                    ->placeholder('—'),
                                                TextEntry::make('url')->hidden(),
                                                TextEntry::make('status_category')->hidden(),
                                            ])
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Description')
                                    ->columnSpanFull()
                                    ->afterHeader([
                                        self::copyMarkdownAction('copyDescriptionMarkdown')
                                            ->visible(fn (ViewJiraIssue $livewire): bool => $livewire->descriptionMarkdown() !== null)
                                            ->alpineClickHandler(fn (ViewJiraIssue $livewire): string => self::copyMarkdownScript((string) $livewire->descriptionMarkdown())),
                                    ])
                                    ->schema([
                                        TextEntry::make('description')
                                            ->hiddenLabel()
                                            ->state(fn (ViewJiraIssue $livewire): ?string => $livewire->descriptionHtml())
                                            ->html()
                                            ->prose()
                                            ->placeholder('No description')
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Pull requests')
                                    ->columnSpanFull()
                                    ->visible(fn (JiraIssue $record): bool => (int) $record->pr_count > 0)
                                    ->schema([
                                        RepeatableEntry::make('pull_requests')
                                            ->hiddenLabel()
                                            ->state(fn (ViewJiraIssue $livewire): array => $livewire->pullRequests())
                                            ->placeholder('Pull request details unavailable')
                                            ->table([
                                                TableColumn::make('Pull request'),
                                                TableColumn::make('Status'),
                                                TableColumn::make('Repository'),
                                                TableColumn::make('Branches'),
                                                TableColumn::make('Author'),
                                                TableColumn::make('Reviewers'),
                                                TableColumn::make('Updated'),
                                            ])
                                            ->schema([
                                                TextEntry::make('title')
                                                    ->url(fn (Get $get): ?string => $get('url'), shouldOpenInNewTab: true)
                                                    ->color('primary'),
                                                TextEntry::make('status')
                                                    ->badge()
                                                    ->color(fn (?string $state): string => JiraPullRequestState::tryFrom((string) $state)?->color() ?? 'gray'),
                                                TextEntry::make('repository')
                                                    ->placeholder('—'),
                                                TextEntry::make('source')
                                                    ->formatStateUsing(fn (?string $state, Get $get): string => $state.' → '.$get('destination')),
                                                TextEntry::make('author')
                                                    ->placeholder('—'),
                                                TextEntry::make('reviewers')
                                                    ->listWithLineBreaks()
                                                    ->placeholder('—'),
                                                TextEntry::make('updated')
                                                    ->dateTime()
                                                    ->placeholder('—'),
                                                TextEntry::make('url')->hidden(),
                                                TextEntry::make('destination')->hidden(),
                                            ])
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Comments')
                                    ->key('comments')
                                    ->columnSpanFull()
                                    ->schema([
                                        Actions::make([
                                            self::addCommentAction(),
                                        ])
                                            ->key('commentActions')
                                            ->columnSpanFull(),
                                        RepeatableEntry::make('comments')
                                            ->hiddenLabel()
                                            ->state(fn (ViewJiraIssue $livewire): array => $livewire->comments())
                                            ->placeholder('No comments')
                                            ->contained(false)
                                            ->schema([
                                                Section::make('Comment')
                                                    ->columnSpanFull()
                                                    ->afterHeader([
                                                        self::copyMarkdownAction('copyCommentMarkdown')
                                                            ->alpineClickHandler(fn (Get $get): string => self::copyMarkdownScript((string) $get('markdown'))),
                                                    ])
                                                    ->schema([
                                                        Fieldset::make('Details')
                                                            ->columnSpanFull()
                                                            ->columns(3)
                                                            ->schema([
                                                                TextEntry::make('author')
                                                                    ->label('Author')
                                                                    ->weight('bold'),
                                                                TextEntry::make('created')
                                                                    ->label('Posted')
                                                                    ->dateTime()
                                                                    ->placeholder('—'),
                                                                TextEntry::make('created')
                                                                    ->label('Ago')
                                                                    ->since()
                                                                    ->placeholder('—'),
                                                            ]),
                                                        TextEntry::make('body')
                                                            ->hiddenLabel()
                                                            ->html()
                                                            ->prose()
                                                            ->columnSpanFull(),
                                                        TextEntry::make('markdown')->hidden(),
                                                    ]),
                                            ])
                                            ->columns(1),
                                    ]),
                            ])
                            ->deferLoading(),
                    ),
            ]);
    }

    /**
     * Build the "Add comment" action for the Comments section header. It posts
     * a new comment to Jira, then refreshes the live content so it appears.
     */
    private static function addCommentAction(): Action
    {
        return Action::make('addComment')
            ->label('Add comment')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->visible(fn (): bool => self::currentUser()->hasJiraConnection())
            ->schema([
                Textarea::make('body')
                    ->label('Comment')
                    ->required()
                    ->rows(8)
                    ->helperText('Supports basic Markdown: **bold**, *italic*, `code`, lists, fenced code blocks, links.'),
            ])
            ->action(function (array $data, Action $action, ViewJiraIssue $livewire, JiraIssue $record): void {
                try {
                    JiraClient::forUser(self::currentUser())
                        ->addComment($record->jira_key, JiraTextToAdf::convert($data['body']));
                } catch (JiraApiException $exception) {
                    Notification::make()->danger()->title('Comment failed')->body($exception->getMessage())->send();

                    $action->halt();
                }

                $livewire->refreshIssueContent();

                Notification::make()->success()->title('Comment added')->send();
            });
    }

    /**
     * Resolve the authenticated user.
     */
    private static function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * Build a small "Copy as Markdown" link action. The click handler is
     * attached by the caller so it can source the correct Markdown string.
     */
    private static function copyMarkdownAction(string $name): Action
    {
        return Action::make($name)
            ->label('Copy as Markdown')
            ->icon(Heroicon::OutlinedClipboardDocument)
            ->color('gray')
            ->size('sm')
            ->link();
    }

    /**
     * Build the Alpine click handler that copies the given Markdown to the
     * clipboard and shows a "Copied" tooltip, mirroring how Filament wires up
     * its own copyable actions.
     */
    private static function copyMarkdownScript(string $markdown): string
    {
        return 'window.navigator.clipboard.writeText('.Js::from($markdown).'); $tooltip('.Js::from('Copied').', { theme: $store.theme })';
    }
}
