<?php

namespace App\Filament\Resources\JiraReleases\Tables;

use App\Filament\Resources\JiraReleases\JiraReleaseResource;
use App\Models\JiraRelease;
use App\Services\Jira\JiraReleaseStatus;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class JiraReleasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->weight(FontWeight::Medium)
                    ->description(fn (JiraRelease $record): ?string => $record->description)
                    ->wrap()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (JiraRelease $record): JiraReleaseStatus => $record->status())
                    ->formatStateUsing(fn (JiraReleaseStatus $state): string => $state->value)
                    ->color(fn (JiraReleaseStatus $state): string => $state->color()),
                TextColumn::make('start_date')
                    ->label('Start date')
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('release_date')
                    ->label('Release date')
                    ->date()
                    ->placeholder('—')
                    ->color(fn (JiraRelease $record): ?string => $record->status() === JiraReleaseStatus::Overdue ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('issues_count')
                    ->label('Tasks')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('progress')
                    ->label('Done')
                    ->state(fn (JiraRelease $record): ?string => self::progressLabel($record))
                    ->description(fn (JiraRelease $record): ?string => $record->issues_count ? "{$record->done_issues_count} of {$record->issues_count} tasks" : null)
                    ->color(fn (JiraRelease $record): ?string => $record->issueProgressPercentage() === 100 ? 'success' : null)
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (JiraRelease $record): string => JiraReleaseResource::getUrl('view', ['record' => $record]))
            ->defaultSort('release_date', 'desc');
    }

    /**
     * The done share of the release's tasks (e.g. `40%`), or null without tasks.
     */
    public static function progressLabel(JiraRelease $record): ?string
    {
        $percentage = $record->issueProgressPercentage();

        return $percentage === null ? null : $percentage.'%';
    }
}
