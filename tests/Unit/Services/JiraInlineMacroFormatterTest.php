<?php

use App\Services\Jira\JiraInlineMacroFormatter;

/**
 * Build an issue macro exactly as Jira's `renderedFields` template emits it,
 * including its newline-led indentation.
 */
function jiraRenderedIssueMacro(string $key, string $status, int $avatarId = 10318): string
{
    return <<<HTML

    <span class="jira-issue-macro" data-jira-key="{$key}" >
                <a href="https://example.atlassian.net/browse/{$key}" class="jira-issue-macro-key issue-link"  title="Summary of {$key}" >
            <img class="icon" src="https://example.atlassian.net/rest/api/2/universal_avatar/view/type/issuetype/avatar/{$avatarId}?size=medium" />
            {$key}
        </a>
                                                    <span class="aui-lozenge aui-lozenge-subtle aui-lozenge-complete jira-macro-single-issue-export-pdf">{$status}</span>
            </span>

    HTML;
}

/**
 * Reduce formatted HTML to its visible text, collapsing whitespace the way a
 * browser does, so line breaks introduced by markup would show up as gaps.
 */
function jiraVisibleText(string $html): string
{
    return mb_trim((string) preg_replace('/\s+/', ' ', strip_tags($html)));
}

test('format renders an issue macro as one inline unit flowing with the text', function () {
    $html = '<p>Everything is recorded in '.jiraRenderedIssueMacro('SUPER-4403', 'Clients Answers Needed')
        .' and in the affected tickets ('.jiraRenderedIssueMacro('SUPER-4364', 'New', 10303)
        .', '.jiraRenderedIssueMacro('SUPER-4365', 'New', 10303).').</p>';

    $formatted = JiraInlineMacroFormatter::format($html);

    expect($formatted)
        ->not->toContain("\n")
        ->toContain('recorded in <span class="jira-issue-macro" data-jira-key="SUPER-4403" style="white-space: nowrap;"><a ')
        ->toContain('style="display: inline; width: auto; height: 1.25em; margin: 0 0.25em 0 0; vertical-align: text-bottom;">SUPER-4403</a> <span class="aui-lozenge')
        ->toContain('Clients Answers Needed</span></span> and in the affected tickets (<span')
        ->toContain('New</span></span>, <span')
        ->toContain('New</span></span>).</p>')
        ->and(jiraVisibleText($formatted))
        ->toBe('Everything is recorded in SUPER-4403 Clients Answers Needed and in the affected tickets (SUPER-4364 New, SUPER-4365 New).');
});

test('format keeps a list item that starts with an issue macro on its first line', function () {
    $html = "<ul>\n\t<li>They will send our numeric game ids, so ".jiraRenderedIssueMacro('SUPER-4391', 'New')
        ." is no longer launch-blocking.</li>\n\t<li>".jiraRenderedIssueMacro('SUPER-4394', 'New')
        ." is ours to solve.</li>\n</ul>";

    $formatted = JiraInlineMacroFormatter::format($html);

    expect($formatted)
        ->toContain('<li><span class="jira-issue-macro" data-jira-key="SUPER-4394"')
        ->toContain('so <span class="jira-issue-macro" data-jira-key="SUPER-4391"')
        ->toContain('New</span></span> is no longer launch-blocking.</li>')
        ->toContain('New</span></span> is ours to solve.</li>');
});

test('format renders emoticons and standalone status lozenges inline', function () {
    $html = '<p>Shipped <img class="emoticon" src="https://example.atlassian.net/images/icons/emoticons/check.png" height="16" width="16" align="absmiddle" alt="" border="0"/> and marked <span class="aui-lozenge aui-lozenge-success">Done</span>.</p>';

    $formatted = JiraInlineMacroFormatter::format($html);

    expect($formatted)
        ->toContain('Shipped <img class="emoticon" src="https://example.atlassian.net/images/icons/emoticons/check.png" height="16" width="16" align="absmiddle" alt="" border="0" style="display: inline;')
        ->toContain('marked <span class="aui-lozenge aui-lozenge-success" style="display: inline;')
        ->toContain('>Done</span>.</p>');
});

test('format leaves html without inline macros untouched', function () {
    $html = "<p>Hi <a href=\"https://example.atlassian.net/secure/ViewProfile.jspa?accountId=1\" class=\"user-hover\">Alice</a>,<br/>\nsee <img src=\"/attachment/1\" /></p>";

    expect(JiraInlineMacroFormatter::format($html))->toBe($html);
});

test('format preserves multibyte text and links around macros', function () {
    $html = '<p>Accounts → <a href="https://example.com/a?b=1&amp;c=2">link</a> '.jiraRenderedIssueMacro('SUPER-1', 'Ąžuolas').'</p>';

    expect(JiraInlineMacroFormatter::format($html))
        ->toStartWith('<p>Accounts → <a href="https://example.com/a?b=1&amp;c=2">link</a> <span class="jira-issue-macro"')
        ->toContain('>Ąžuolas</span></span></p>');
});
