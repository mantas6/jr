<?php

namespace App\Filament\Resources\JiraIssues\Schemas;

use App\Filament\Resources\JiraIssues\Pages\ViewJiraIssue;
use App\Filament\Resources\JiraIssues\Tables\JiraIssuesTable;
use App\Models\JiraIssue;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                            ->color(fn (JiraIssue $record): string => self::statusColor($record->status_category)),
                        TextEntry::make('priority')
                            ->badge()
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
                        TextEntry::make('summary')
                            ->columnSpanFull(),
                    ]),
                Section::make('Activity')
                    ->columns(3)
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
                    ]),
                Group::make()
                    ->key('jiraContent')
                    ->columnSpanFull()
                    ->schema(
                        Schema::make()
                            ->components([
                                Section::make('Description')
                                    ->columnSpanFull()
                                    ->schema([
                                        TextEntry::make('description')
                                            ->hiddenLabel()
                                            ->state(fn (ViewJiraIssue $livewire): ?string => $livewire->descriptionHtml())
                                            ->html()
                                            ->prose()
                                            ->placeholder('No description')
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Comments')
                                    ->columnSpanFull()
                                    ->schema([
                                        RepeatableEntry::make('comments')
                                            ->hiddenLabel()
                                            ->state(fn (ViewJiraIssue $livewire): array => $livewire->comments())
                                            ->placeholder('No comments')
                                            ->schema([
                                                TextEntry::make('author')
                                                    ->label('Author')
                                                    ->weight('bold'),
                                                TextEntry::make('created')
                                                    ->label('Posted')
                                                    ->dateTime()
                                                    ->placeholder('—'),
                                                TextEntry::make('body')
                                                    ->hiddenLabel()
                                                    ->html()
                                                    ->prose()
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(2),
                                    ]),
                            ])
                            ->deferLoading(),
                    ),
            ]);
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
}
