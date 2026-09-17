<?php

use App\Services\Jira\JiraIssueReferenceParser;

test('it extracts a key from a full browse link', function () {
    expect(JiraIssueReferenceParser::parse('https://site.atlassian.net/browse/PROJ-123', null))
        ->toBe(['PROJ-123']);
});

test('it extracts a key from a browse link with query parameters', function () {
    expect(JiraIssueReferenceParser::parse('https://site.atlassian.net/browse/PROJ-123?focusedCommentId=1', null))
        ->toBe(['PROJ-123']);
});

test('it extracts a key from a board link selectedIssue parameter', function () {
    $input = 'https://site.atlassian.net/jira/software/c/projects/PROJ/boards/1?selectedIssue=PROJ-123';

    expect(JiraIssueReferenceParser::parse($input, null))->toBe(['PROJ-123']);
});

test('it accepts a bare key and upper-cases it', function () {
    expect(JiraIssueReferenceParser::parse('proj-123', null))->toBe(['PROJ-123']);
});

test('it ignores a name trailing the key', function (string $input) {
    expect(JiraIssueReferenceParser::parse($input, null))->toBe(['PROJ-123']);
})->with([
    'PROJ-123 Fix the login bug',
    'PROJ-123: Fix the login bug',
    '[PROJ-123] Fix the login bug',
]);

test('it extracts multiple keys from a single line', function () {
    expect(JiraIssueReferenceParser::parse('PROJ-1 and PROJ-2', null))
        ->toBe(['PROJ-1', 'PROJ-2']);
});

test('it turns bare numbers into keys using the default project', function () {
    expect(JiraIssueReferenceParser::parse('123', 'PROJ'))->toBe(['PROJ-123']);
});

test('it turns hash-prefixed numbers into keys using the default project', function () {
    expect(JiraIssueReferenceParser::parse('#123', 'PROJ'))->toBe(['PROJ-123']);
});

test('it skips bare numbers when no default project is provided', function () {
    expect(JiraIssueReferenceParser::parse('123', null))->toBe([]);
});

test('it de-duplicates repeated keys while preserving input order', function () {
    $input = "PROJ-2\nPROJ-1\nproj-2\nhttps://site.atlassian.net/browse/PROJ-1";

    expect(JiraIssueReferenceParser::parse($input, null))->toBe(['PROJ-2', 'PROJ-1']);
});

test('it parses mixed links, keys, and numbers separated by newlines and commas', function () {
    $input = "https://site.atlassian.net/browse/PROJ-10, ABC-5\n#7";

    expect(JiraIssueReferenceParser::parse($input, 'PROJ'))
        ->toBe(['PROJ-10', 'ABC-5', 'PROJ-7']);
});

test('it returns an empty array for garbage input', function () {
    expect(JiraIssueReferenceParser::parse('hello world!!!', 'PROJ'))->toBe([]);
});

test('it returns an empty array for blank input', function () {
    expect(JiraIssueReferenceParser::parse('   ', 'PROJ'))->toBe([]);
});
