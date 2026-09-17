<?php

use App\Services\Jira\JiraTextToAdf;

test('empty input produces a doc with no content', function () {
    expect(JiraTextToAdf::convert(''))->toBe([
        'version' => 1,
        'type' => 'doc',
        'content' => [],
    ]);
});

test('a single line becomes one paragraph', function () {
    expect(JiraTextToAdf::convert('Hello world'))->toBe([
        'version' => 1,
        'type' => 'doc',
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello world'],
                ],
            ],
        ],
    ]);
});

test('single newlines inside a paragraph become hard breaks', function () {
    expect(JiraTextToAdf::convert("First line\nSecond line")['content'])->toBe([
        [
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'First line'],
                ['type' => 'hardBreak'],
                ['type' => 'text', 'text' => 'Second line'],
            ],
        ],
    ]);
});

test('blank lines split paragraphs', function () {
    $content = JiraTextToAdf::convert("One\n\nTwo")['content'];

    expect($content)->toHaveCount(2)
        ->and($content[0]['type'])->toBe('paragraph')
        ->and($content[1]['type'])->toBe('paragraph')
        ->and($content[1]['content'][0]['text'])->toBe('Two');
});

test('dash and asterisk lines form a bullet list', function () {
    expect(JiraTextToAdf::convert("- First\n* Second")['content'])->toBe([
        [
            'type' => 'bulletList',
            'content' => [
                [
                    'type' => 'listItem',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First']]],
                    ],
                ],
                [
                    'type' => 'listItem',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second']]],
                    ],
                ],
            ],
        ],
    ]);
});

test('numbered lines form an ordered list', function () {
    expect(JiraTextToAdf::convert("1. First\n2) Second")['content'])->toBe([
        [
            'type' => 'orderedList',
            'content' => [
                [
                    'type' => 'listItem',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'First']]],
                    ],
                ],
                [
                    'type' => 'listItem',
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Second']]],
                    ],
                ],
            ],
        ],
    ]);
});

test('hash-prefixed lines become headings with the matching level', function () {
    expect(JiraTextToAdf::convert('### Deep heading')['content'])->toBe([
        [
            'type' => 'heading',
            'attrs' => ['level' => 3],
            'content' => [['type' => 'text', 'text' => 'Deep heading']],
        ],
    ]);
});

test('a fenced code block keeps its language and raw content', function () {
    $text = "```php\n<?php echo 'hi';\n```";

    expect(JiraTextToAdf::convert($text)['content'])->toBe([
        [
            'type' => 'codeBlock',
            'attrs' => ['language' => 'php'],
            'content' => [['type' => 'text', 'text' => "<?php echo 'hi';"]],
        ],
    ]);
});

test('a fenced code block without a language omits the attrs', function () {
    $text = "```\nplain code\n```";

    expect(JiraTextToAdf::convert($text)['content'])->toBe([
        [
            'type' => 'codeBlock',
            'content' => [['type' => 'text', 'text' => 'plain code']],
        ],
    ]);
});

test('inline strong text carries a strong mark', function () {
    expect(JiraTextToAdf::convert('Say **hello** now')['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'Say '],
        ['type' => 'text', 'text' => 'hello', 'marks' => [['type' => 'strong']]],
        ['type' => 'text', 'text' => ' now'],
    ]);
});

test('inline italic text carries an em mark for both delimiters', function () {
    expect(JiraTextToAdf::convert('an *italic* and _also_ word')['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'an '],
        ['type' => 'text', 'text' => 'italic', 'marks' => [['type' => 'em']]],
        ['type' => 'text', 'text' => ' and '],
        ['type' => 'text', 'text' => 'also', 'marks' => [['type' => 'em']]],
        ['type' => 'text', 'text' => ' word'],
    ]);
});

test('inline code carries a code mark', function () {
    expect(JiraTextToAdf::convert('run `artisan test` please')['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'run '],
        ['type' => 'text', 'text' => 'artisan test', 'marks' => [['type' => 'code']]],
        ['type' => 'text', 'text' => ' please'],
    ]);
});

test('a markdown link becomes a link mark with an href', function () {
    expect(JiraTextToAdf::convert('see [the docs](https://example.com/docs)')['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'see '],
        ['type' => 'text', 'text' => 'the docs', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/docs']]]],
    ]);
});

test('a bare url becomes a link mark pointing at itself', function () {
    expect(JiraTextToAdf::convert('visit https://example.com now')['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => 'visit '],
        ['type' => 'text', 'text' => 'https://example.com', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
        ['type' => 'text', 'text' => ' now'],
    ]);
});

test('a mixed document keeps its blocks in order', function () {
    $text = "# Title\n\nA paragraph.\n\n- one\n- two";

    $content = JiraTextToAdf::convert($text)['content'];

    expect($content)->toHaveCount(3)
        ->and($content[0]['type'])->toBe('heading')
        ->and($content[0]['attrs']['level'])->toBe(1)
        ->and($content[1]['type'])->toBe('paragraph')
        ->and($content[1]['content'][0]['text'])->toBe('A paragraph.')
        ->and($content[2]['type'])->toBe('bulletList')
        ->and($content[2]['content'])->toHaveCount(2);
});
