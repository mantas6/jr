<?php

use App\Models\JiraIssue;
use App\Services\Jira\ConcerningTasksExporter;
use Illuminate\Support\Collection;

function issue(string $url, string $summary): JiraIssue
{
    return new JiraIssue(['jira_url' => $url, 'summary' => $summary]);
}

test('it renders each task as a link and summary separated by a blank line', function () {
    $issues = new Collection([
        issue('https://site.atlassian.net/browse/PROJ-1', 'First task'),
        issue('https://site.atlassian.net/browse/PROJ-2', 'Second task'),
    ]);

    expect(ConcerningTasksExporter::toText($issues))->toBe(
        "https://site.atlassian.net/browse/PROJ-1\nFirst task\n\n".
        "https://site.atlassian.net/browse/PROJ-2\nSecond task\n"
    );
});

test('a single task ends with one trailing newline', function () {
    $issues = new Collection([
        issue('https://site.atlassian.net/browse/PROJ-1', 'Only task'),
    ]);

    expect(ConcerningTasksExporter::toText($issues))->toBe(
        "https://site.atlassian.net/browse/PROJ-1\nOnly task\n"
    );
});

test('an empty collection renders an empty string', function () {
    expect(ConcerningTasksExporter::toText(new Collection()))->toBe('');
});
