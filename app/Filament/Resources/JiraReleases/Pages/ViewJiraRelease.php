<?php

namespace App\Filament\Resources\JiraReleases\Pages;

use App\Filament\Resources\JiraReleases\JiraReleaseResource;
use App\Models\JiraRelease;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewJiraRelease extends ViewRecord
{
    protected static string $resource = JiraReleaseResource::class;

    /**
     * Show the release name (e.g. v1.2.0) as the page title.
     */
    public function getTitle(): string
    {
        return $this->currentRecord()->name;
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('openInJira')
                ->label('Open in Jira')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (): string => $this->currentRecord()->jira_url, shouldOpenInNewTab: true),
        ];
    }

    private function currentRecord(): JiraRelease
    {
        /** @var JiraRelease $record */
        $record = $this->record;

        return $record;
    }
}
