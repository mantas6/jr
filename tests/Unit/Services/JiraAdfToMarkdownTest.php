<?php

use App\Services\Jira\JiraAdfToMarkdown;

/**
 * Wrap the given ADF nodes in a `doc` node.
 *
 * @param  array<int, array<string, mixed>>  $content
 * @return array<string, mixed>
 */
function adfDoc(array $content): array
{
    return ['type' => 'doc', 'version' => 1, 'content' => $content];
}

/**
 * Build an ADF paragraph from the given inline nodes.
 *
 * @param  array<int, array<string, mixed>>  $content
 * @return array<string, mixed>
 */
function adfParagraph(array $content): array
{
    return ['type' => 'paragraph', 'content' => $content];
}

/**
 * Build an ADF text node with optional marks.
 *
 * @param  array<int, array<string, mixed>>  $marks
 * @return array<string, mixed>
 */
function adfText(string $text, array $marks = []): array
{
    $node = ['type' => 'text', 'text' => $text];

    if ($marks !== []) {
        $node['marks'] = $marks;
    }

    return $node;
}

test('convert returns an empty string for non-array input', function () {
    expect(JiraAdfToMarkdown::convert(null))->toBe('')
        ->and(JiraAdfToMarkdown::convert('text'))->toBe('');
});

test('convert renders a paragraph with inline marks', function () {
    $doc = adfDoc([
        adfParagraph([
            adfText('Plain '),
            adfText('bold', [['type' => 'strong']]),
            adfText(' '),
            adfText('italic', [['type' => 'em']]),
            adfText(' '),
            adfText('code', [['type' => 'code']]),
            adfText(' '),
            adfText('gone', [['type' => 'strike']]),
            adfText(' '),
            adfText('kept', [['type' => 'underline']]),
        ]),
    ]);

    expect(JiraAdfToMarkdown::convert($doc))
        ->toBe('Plain **bold** *italic* `code` ~~gone~~ kept');
});

test('convert renders headings with the matching number of hashes', function () {
    $doc = adfDoc([
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [adfText('Title')]],
        ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [adfText('Sub')]],
    ]);

    expect(JiraAdfToMarkdown::convert($doc))->toBe("# Title\n\n### Sub");
});

test('convert renders nested bullet and ordered lists with indentation', function () {
    $doc = adfDoc([
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [
                adfParagraph([adfText('First')]),
                ['type' => 'orderedList', 'content' => [
                    ['type' => 'listItem', 'content' => [adfParagraph([adfText('Nested one')])]],
                    ['type' => 'listItem', 'content' => [adfParagraph([adfText('Nested two')])]],
                ]],
            ]],
            ['type' => 'listItem', 'content' => [adfParagraph([adfText('Second')])]],
        ]],
    ]);

    expect(JiraAdfToMarkdown::convert($doc))->toBe(
        "- First\n  1. Nested one\n  2. Nested two\n- Second"
    );
});

test('convert renders a fenced code block with its language', function () {
    $doc = adfDoc([
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [
            adfText("echo 'hi';"),
        ]],
    ]);

    expect(JiraAdfToMarkdown::convert($doc))->toBe("```php\necho 'hi';\n```");
});

test('convert renders a blockquote by prefixing each line', function () {
    $doc = adfDoc([
        ['type' => 'blockquote', 'content' => [
            adfParagraph([adfText('Quoted line')]),
        ]],
    ]);

    expect(JiraAdfToMarkdown::convert($doc))->toBe('> Quoted line');
});

test('convert renders a link mark as a markdown link', function () {
    $doc = adfDoc([
        adfParagraph([
            adfText('OpenCode', [['type' => 'link', 'attrs' => ['href' => 'https://example.test']]]),
        ]),
    ]);

    expect(JiraAdfToMarkdown::convert($doc))->toBe('[OpenCode](https://example.test)');
});

test('convert renders a mention without doubling the leading at sign', function () {
    $withAt = adfDoc([adfParagraph([['type' => 'mention', 'attrs' => ['text' => '@Alice']]])]);
    $withoutAt = adfDoc([adfParagraph([['type' => 'mention', 'attrs' => ['text' => 'Bob']]])]);

    expect(JiraAdfToMarkdown::convert($withAt))->toBe('@Alice')
        ->and(JiraAdfToMarkdown::convert($withoutAt))->toBe('@Bob');
});

test('convert renders a simple pipe table with a header separator', function () {
    $doc = adfDoc([
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => [
                ['type' => 'tableHeader', 'content' => [adfParagraph([adfText('Name')])]],
                ['type' => 'tableHeader', 'content' => [adfParagraph([adfText('Role')])]],
            ]],
            ['type' => 'tableRow', 'content' => [
                ['type' => 'tableCell', 'content' => [adfParagraph([adfText('Alice')])]],
                ['type' => 'tableCell', 'content' => [adfParagraph([adfText('Admin')])]],
            ]],
        ]],
    ]);

    expect(JiraAdfToMarkdown::convert($doc))->toBe(
        "| Name | Role |\n| --- | --- |\n| Alice | Admin |"
    );
});

test('convert renders a hard break as a newline within a paragraph', function () {
    $doc = adfDoc([
        adfParagraph([
            adfText('Line one'),
            ['type' => 'hardBreak'],
            adfText('Line two'),
        ]),
    ]);

    expect(JiraAdfToMarkdown::convert($doc))->toBe("Line one\nLine two");
});

test('convert renders task lists as markdown checkboxes', function () {
    $doc = adfDoc([
        ['type' => 'taskList', 'content' => [
            ['type' => 'taskItem', 'attrs' => ['state' => 'DONE'], 'content' => [adfText('Done thing')]],
            ['type' => 'taskItem', 'attrs' => ['state' => 'TODO'], 'content' => [adfText('Pending thing')]],
        ]],
    ]);

    expect(JiraAdfToMarkdown::convert($doc))->toBe("- [x] Done thing\n- [ ] Pending thing");
});

test('convert recurses into unknown node types', function () {
    $doc = adfDoc([
        adfParagraph([
            ['type' => 'wobble', 'content' => [adfText('surprise')]],
        ]),
    ]);

    expect(JiraAdfToMarkdown::convert($doc))->toBe('surprise');
});
