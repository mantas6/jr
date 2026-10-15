<?php

declare(strict_types=1);

namespace App\Services\Jira;

class JiraRichTextToAdf
{
    /**
     * TipTap mark names mapped to their ADF equivalents. Other marks (colour,
     * highlight, sub/superscript) have no ADF counterpart and are dropped.
     *
     * @var array<string, string>
     */
    private const MARKS = [
        'bold' => 'strong',
        'italic' => 'em',
        'strike' => 'strike',
        'underline' => 'underline',
        'code' => 'code',
    ];

    /**
     * Convert a Filament rich editor (TipTap JSON) document into an Atlassian
     * Document Format (ADF) document suitable for posting to Jira.
     *
     * Paragraphs, headings, lists, code blocks, blockquotes, rules, hard
     * breaks and the bold/italic/strike/underline/code/link marks map to their
     * ADF nodes, and `@` mentions become ADF `mention` nodes carrying the Jira
     * account id. Unformatted text keeps the Markdown support of
     * {@see JiraTextToAdf}: top-level plain paragraphs are parsed as Markdown
     * blocks (so pasted lists and fences still work) and other plain text runs
     * as inline Markdown (bold, italic, code, links and bare URLs).
     *
     * @return array{version: int, type: string, content: list<array<string, mixed>>}
     */
    public static function convert(mixed $document): array
    {
        $nodes = is_array($document) ? self::childNodes($document) : [];

        return [
            'version' => 1,
            'type' => 'doc',
            'content' => self::mergeAdjacentLists(self::blocks($nodes, parseMarkdownBlocks: true)),
        ];
    }

    /**
     * Convert a list of TipTap block nodes into ADF block nodes.
     *
     * Markdown block parsing is limited to the top level so list items and
     * blockquotes always start with a paragraph, as ADF requires.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function blocks(array $nodes, bool $parseMarkdownBlocks = false): array
    {
        $blocks = [];

        foreach ($nodes as $node) {
            array_push($blocks, ...self::block($node, $parseMarkdownBlocks));
        }

        return $blocks;
    }

    /**
     * Convert a single TipTap block node into zero or more ADF block nodes.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function block(array $node, bool $parseMarkdownBlocks): array
    {
        $children = self::childNodes($node);

        return match ($node['type'] ?? null) {
            'paragraph' => self::paragraph($node, $parseMarkdownBlocks),
            'heading' => self::withInlineContent([
                'type' => 'heading',
                'attrs' => ['level' => max(1, min(6, (int) data_get($node, 'attrs.level', 1)))],
            ], $children),
            'bulletList', 'orderedList' => self::list((string) $node['type'], $children),
            'codeBlock' => [self::codeBlock($node)],
            'blockquote' => self::withContent(['type' => 'blockquote'], self::blocks($children)),
            'horizontalRule' => [['type' => 'rule']],
            default => self::blocks($children, $parseMarkdownBlocks),
        };
    }

    /**
     * Convert a paragraph, parsing plain-text paragraphs as Markdown blocks
     * when allowed and otherwise keeping its inline formatting and mentions.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function paragraph(array $node, bool $parseMarkdownBlocks): array
    {
        $children = self::childNodes($node);

        if ($parseMarkdownBlocks && self::isPlainText($children)) {
            return JiraTextToAdf::convert(self::plainText($children))['content'];
        }

        return self::withInlineContent(['type' => 'paragraph'], $children);
    }

    /**
     * Convert a bullet or ordered list and its items.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function list(string $type, array $items): array
    {
        $content = [];

        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'listItem') {
                continue;
            }

            $itemContent = self::blocks(self::childNodes($item));

            if (($itemContent[0]['type'] ?? null) !== 'paragraph') {
                array_unshift($itemContent, ['type' => 'paragraph', 'content' => []]);
            }

            $content[] = ['type' => 'listItem', 'content' => $itemContent];
        }

        return self::withContent(['type' => $type], $content);
    }

    /**
     * Convert a code block, keeping its language when set.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private static function codeBlock(array $node): array
    {
        $code = self::plainText(self::childNodes($node));
        $language = data_get($node, 'attrs.language');

        $block = ['type' => 'codeBlock'];

        if (is_string($language) && $language !== '') {
            $block['attrs'] = ['language' => $language];
        }

        $block['content'] = $code === '' ? [] : [['type' => 'text', 'text' => $code]];

        return $block;
    }

    /**
     * Convert TipTap inline nodes into ADF inline nodes.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function inline(array $nodes): array
    {
        $inline = [];

        foreach ($nodes as $node) {
            $converted = match ($node['type'] ?? null) {
                'text' => self::text($node),
                'hardBreak' => [['type' => 'hardBreak']],
                'mention' => self::mention($node),
                default => self::inline(self::childNodes($node)),
            };

            array_push($inline, ...$converted);
        }

        return $inline;
    }

    /**
     * Convert a text node, mapping its marks or, when unformatted, parsing
     * it as inline Markdown.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function text(array $node): array
    {
        $text = (string) ($node['text'] ?? '');

        if ($text === '') {
            return [];
        }

        $marks = self::marks($node);

        if ($marks === []) {
            return JiraTextToAdf::inline($text);
        }

        return [['type' => 'text', 'text' => $text, 'marks' => $marks]];
    }

    /**
     * Map a text node's TipTap marks to ADF marks. ADF only allows `code`
     * alongside a link, so other marks are dropped from inline code.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function marks(array $node): array
    {
        $marks = [];

        foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
            $type = is_array($mark) ? ($mark['type'] ?? null) : null;

            if ($type === 'link') {
                $href = data_get($mark, 'attrs.href');

                if (is_string($href) && $href !== '') {
                    $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
                }

                continue;
            }

            if (is_string($type) && isset(self::MARKS[$type])) {
                $marks[] = ['type' => self::MARKS[$type]];
            }
        }

        if (in_array(['type' => 'code'], $marks, true)) {
            return array_values(array_filter(
                $marks,
                static fn (array $mark): bool => in_array($mark['type'], ['code', 'link'], true),
            ));
        }

        return $marks;
    }

    /**
     * Convert an editor mention into an ADF mention of the Jira account.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function mention(array $node): array
    {
        $id = data_get($node, 'attrs.id');

        if (!is_string($id) || $id === '') {
            return [];
        }

        $attrs = ['id' => $id];
        $label = data_get($node, 'attrs.label');

        if (is_string($label) && $label !== '') {
            $attrs['text'] = '@'.mb_ltrim($label, '@');
        }

        return [['type' => 'mention', 'attrs' => $attrs]];
    }

    /**
     * Attach converted inline content to a node, dropping it when empty.
     *
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $children
     * @return list<array<string, mixed>>
     */
    private static function withInlineContent(array $node, array $children): array
    {
        return self::withContent($node, self::inline($children));
    }

    /**
     * Attach content to a node, returning no node at all when it is empty.
     *
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $content
     * @return list<array<string, mixed>>
     */
    private static function withContent(array $node, array $content): array
    {
        return $content === [] ? [] : [[...$node, 'content' => $content]];
    }

    /**
     * Merge adjacent lists of the same type, which plain-text paragraphs
     * parsed one at a time (e.g. pasted `- item` lines) would otherwise split.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function mergeAdjacentLists(array $blocks): array
    {
        $merged = [];

        foreach ($blocks as $block) {
            $previous = array_key_last($merged);
            $type = $block['type'] ?? null;

            if (
                $previous !== null
                && in_array($type, ['bulletList', 'orderedList'], true)
                && ($merged[$previous]['type'] ?? null) === $type
                && is_array($merged[$previous]['content'] ?? null)
                && is_array($block['content'] ?? null)
            ) {
                $merged[$previous]['content'] = [...$merged[$previous]['content'], ...$block['content']];

                continue;
            }

            $merged[] = $block;
        }

        return $merged;
    }

    /**
     * Determine whether inline nodes are only unformatted text and breaks.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private static function isPlainText(array $nodes): bool
    {
        foreach ($nodes as $node) {
            $type = $node['type'] ?? null;

            if ($type === 'hardBreak') {
                continue;
            }

            if ($type !== 'text' || self::marks($node) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Concatenate the text of inline nodes, turning hard breaks into newlines.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private static function plainText(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            $text .= match ($node['type'] ?? null) {
                'text' => (string) ($node['text'] ?? ''),
                'hardBreak' => "\n",
                default => self::plainText(self::childNodes($node)),
            };
        }

        return $text;
    }

    /**
     * Return a node's child nodes as a list of arrays.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function childNodes(array $node): array
    {
        $content = $node['content'] ?? null;

        if (!is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, 'is_array'));
    }
}
