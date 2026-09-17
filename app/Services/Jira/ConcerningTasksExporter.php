<?php

declare(strict_types=1);

namespace App\Services\Jira;

use App\Models\JiraIssue;
use Illuminate\Support\Collection;

/**
 * Renders a collection of Jira issues as a plain-text export: for each task, its
 * full Jira link on the first line and its summary on the second, separated by a
 * blank line, ending with a single trailing newline.
 */
class ConcerningTasksExporter
{
    /**
     * @param  Collection<int, JiraIssue>  $issues
     */
    public static function toText(Collection $issues): string
    {
        if ($issues->isEmpty()) {
            return '';
        }

        return $issues
            ->map(fn (JiraIssue $issue): string => $issue->jira_url."\n".$issue->summary."\n")
            ->implode("\n");
    }
}
