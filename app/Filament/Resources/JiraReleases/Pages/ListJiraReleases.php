<?php

namespace App\Filament\Resources\JiraReleases\Pages;

use App\Filament\Resources\JiraIssues\Concerns\ShowsSyncStatusSubheading;
use App\Filament\Resources\JiraReleases\JiraReleaseResource;
use App\Models\JiraRelease;
use Closure;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListJiraReleases extends ListRecords
{
    use ShowsSyncStatusSubheading;

    protected static string $resource = JiraReleaseResource::class;

    /**
     * Split releases into unreleased, released and archived ones, each with a
     * count badge.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $unreleasedQuery = $this->unreleasedTabQuery(...);
        $releasedQuery = $this->releasedTabQuery(...);
        $archivedQuery = $this->archivedTabQuery(...);

        return [
            'unreleased' => Tab::make('Unreleased')
                ->modifyQueryUsing($unreleasedQuery)
                ->badge($this->tabCount($unreleasedQuery)),
            'released' => Tab::make('Released')
                ->modifyQueryUsing($releasedQuery)
                ->badge($this->tabCount($releasedQuery)),
            'archived' => Tab::make('Archived')
                ->modifyQueryUsing($archivedQuery)
                ->badge($this->tabCount($archivedQuery)),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'unreleased';
    }

    /**
     * Count the current user's releases after applying a tab's query modifier.
     *
     * @param  Closure(Builder<JiraRelease>): Builder<JiraRelease>  $modifyQuery
     */
    private function tabCount(Closure $modifyQuery): int
    {
        return $modifyQuery(JiraRelease::query()->where('user_id', auth()->id()))->count();
    }

    /**
     * Releases that are neither released nor archived.
     *
     * @param  Builder<JiraRelease>  $query
     * @return Builder<JiraRelease>
     */
    private function unreleasedTabQuery(Builder $query): Builder
    {
        return $query->unreleased();
    }

    /**
     * Released releases that are not archived.
     *
     * @param  Builder<JiraRelease>  $query
     * @return Builder<JiraRelease>
     */
    private function releasedTabQuery(Builder $query): Builder
    {
        return $query->released();
    }

    /**
     * Archived releases, released or not.
     *
     * @param  Builder<JiraRelease>  $query
     * @return Builder<JiraRelease>
     */
    private function archivedTabQuery(Builder $query): Builder
    {
        return $query->archived();
    }
}
