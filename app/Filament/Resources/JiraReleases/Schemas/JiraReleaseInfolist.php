<?php

namespace App\Filament\Resources\JiraReleases\Schemas;

use App\Filament\Resources\JiraReleases\Tables\JiraReleasesTable;
use App\Models\JiraRelease;
use App\Services\Jira\JiraReleaseStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JiraReleaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Release')
                    ->columns(4)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name')
                            ->url(fn (JiraRelease $record): string => $record->jira_url, shouldOpenInNewTab: true)
                            ->color('primary'),
                        TextEntry::make('status')
                            ->badge()
                            ->state(fn (JiraRelease $record): JiraReleaseStatus => $record->status())
                            ->formatStateUsing(fn (JiraReleaseStatus $state): string => $state->value)
                            ->color(fn (JiraReleaseStatus $state): string => $state->color()),
                        TextEntry::make('start_date')
                            ->label('Start date')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('release_date')
                            ->label('Release date')
                            ->date()
                            ->color(fn (JiraRelease $record): ?string => $record->status() === JiraReleaseStatus::Overdue ? 'danger' : null)
                            ->placeholder('—'),
                        TextEntry::make('issues_count')
                            ->label('Tasks')
                            ->state(fn (JiraRelease $record): int => (int) $record->issues_count),
                        TextEntry::make('progress')
                            ->label('Done')
                            ->state(fn (JiraRelease $record): ?string => JiraReleasesTable::progressLabel($record))
                            ->helperText(fn (JiraRelease $record): ?string => $record->issues_count ? "{$record->done_issues_count} of {$record->issues_count} tasks" : null)
                            ->placeholder('—'),
                        TextEntry::make('last_synced_at')
                            ->label('Last synced')
                            ->dateTime(),
                        TextEntry::make('description')
                            ->placeholder('No description')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
