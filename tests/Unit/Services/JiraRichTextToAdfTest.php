<?php

use App\Services\Jira\JiraRichTextToAdf;

/**
 * Wrap TipTap nodes in a TipTap `doc` node, as the rich editor's JSON state.
 *
 * @param  list<array<string, mixed>>  $content
 * @return array<string, mixed>
 */
function tipTapDoc(array $content): array
{
    return ['type' => 'doc', 'content' => $content];
}

/**
 * Build a TipTap paragraph from the given inline nodes.
 *
 * @param  list<array<string, mixed>>  $content
 * @return array<string, mixed>
 */
function tipTapParagraph(array $content): array
{
    return ['type' => 'paragraph', 'content' => $content];
}

test('convert turns editor mentions into ADF mentions of the Jira account', function () {
    $adf = JiraRichTextToAdf::convert(tipTapDoc([
        tipTapParagraph([
            ['type' => 'mention', 'attrs' => ['id' => '712020:abc', 'label' => 'Rasa Sataite', 'char' => '@']],
            ['type' => 'text', 'text' => ' please estimate '],
            ['type' => 'mention', 'attrs' => ['id' => '5f75cd0b', 'label' => null, 'char' => '@']],
            ['type' => 'mention', 'attrs' => ['id' => null, 'label' => 'Ghost']],
        ]),
    ]));

    expect($adf)->toBe([
        'version' => 1,
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'mention', 'attrs' => ['id' => '712020:abc', 'text' => '@Rasa Sataite']],
                ['type' => 'text', 'text' => ' please estimate '],
                ['type' => 'mention', 'attrs' => ['id' => '5f75cd0b']],
            ],
        ]],
    ]);
});

test('convert maps editor marks to ADF marks and keeps markdown in unformatted text', function () {
    $adf = JiraRichTextToAdf::convert(tipTapDoc([
        tipTapParagraph([
            ['type' => 'mention', 'attrs' => ['id' => 'acc-1', 'label' => 'Alice']],
            ['type' => 'text', 'text' => ' see '],
            ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold'], ['type' => 'textColor']]],
            ['type' => 'text', 'text' => 'snippet', 'marks' => [['type' => 'italic'], ['type' => 'code']]],
            ['type' => 'text', 'text' => 'docs', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'and **this** too'],
        ]),
    ]));

    expect($adf['content'][0]['content'])->toBe([
        ['type' => 'mention', 'attrs' => ['id' => 'acc-1', 'text' => '@Alice']],
        ['type' => 'text', 'text' => ' see '],
        ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'strong']]],
        ['type' => 'text', 'text' => 'snippet', 'marks' => [['type' => 'code']]],
        ['type' => 'text', 'text' => 'docs', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
        ['type' => 'hardBreak'],
        ['type' => 'text', 'text' => 'and '],
        ['type' => 'text', 'text' => 'this', 'marks' => [['type' => 'strong']]],
        ['type' => 'text', 'text' => ' too'],
    ]);
});

test('convert maps editor blocks to ADF blocks', function () {
    $adf = JiraRichTextToAdf::convert(tipTapDoc([
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Plan']]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [
                tipTapParagraph([['type' => 'mention', 'attrs' => ['id' => 'acc-1', 'label' => 'Alice']]]),
                ['type' => 'orderedList', 'content' => [
                    ['type' => 'listItem', 'content' => [tipTapParagraph([['type' => 'text', 'text' => '1. nested']])]],
                ]],
            ]],
            ['type' => 'listItem', 'content' => [tipTapParagraph([])]],
        ]],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
        ['type' => 'blockquote', 'content' => [tipTapParagraph([['type' => 'text', 'text' => 'Quoted']])]],
        ['type' => 'horizontalRule'],
        tipTapParagraph([]),
    ]));

    expect($adf['content'])->toBe([
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Plan']]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => 'acc-1', 'text' => '@Alice']]]],
                ['type' => 'orderedList', 'content' => [
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '1. nested']]]]],
                ]],
            ]],
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => []]]],
        ]],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
        ['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Quoted']]]]],
        ['type' => 'rule'],
    ]);
});

test('convert parses pasted plain-text markdown paragraphs as blocks', function () {
    $adf = JiraRichTextToAdf::convert(tipTapDoc([
        tipTapParagraph([['type' => 'text', 'text' => '- first']]),
        tipTapParagraph([['type' => 'text', 'text' => '- second']]),
        tipTapParagraph([
            ['type' => 'text', 'text' => '```'],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'code'],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => '```'],
        ]),
    ]));

    expect($adf['content'])->toBe([
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'first']]]]],
            ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'second']]]]],
        ]],
        ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'code']]],
    ]);
});

test('convert returns an empty document for missing editor state', function (mixed $state) {
    expect(JiraRichTextToAdf::convert($state))->toBe(['version' => 1, 'type' => 'doc', 'content' => []]);
})->with([
    'null' => [null],
    'empty doc' => [tipTapDoc([])],
]);
