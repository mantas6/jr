<?php

declare(strict_types=1);

namespace App\Services\Jira;

class JiraAdfToMarkdown
{
    /**
     * Convert an Atlassian Document Format (ADF) document into Markdown.
     *
     * Unknown node types are handled gracefully by recursing into their
     * content. The result is normalized to at most two consecutive newlines
     * and trimmed.
     *
     * @param  mixed  $document  The ADF document node (typically an array).
     */
    public static function convert(mixed $document): string
    {
        if (!is_array($document)) {
            return '';
        }

        return self::normalize(self::renderNode($document));
    }

    /**
     * Render a single ADF node into Markdown.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderNode(array $node, int $depth = 0): string
    {
        return match ($node['type'] ?? null) {
            'doc' => self::renderBlockChildren($node, $depth),
            'paragraph' => self::renderInlineChildren($node),
            'text' => self::renderText($node),
            'heading' => self::renderHeading($node),
            'bulletList' => self::renderList($node, $depth, ordered: false),
            'orderedList' => self::renderList($node, $depth, ordered: true),
            'listItem' => self::renderBlockChildren($node, $depth),
            'codeBlock' => self::renderCodeBlock($node),
            'blockquote', 'panel' => self::renderBlockquote($node, $depth),
            'hardBreak' => "\n",
            'rule' => '---',
            'mention' => self::renderMention($node),
            'emoji' => self::renderEmoji($node),
            'inlineCard' => (string) data_get($node, 'attrs.url', ''),
            'table' => self::renderTable($node),
            'mediaSingle', 'mediaGroup' => self::renderMediaContainer($node),
            'media' => self::renderMedia($node),
            'taskList' => self::renderTaskList($node),
            'taskItem' => self::renderTaskItem($node),
            'expand', 'nestedExpand' => self::renderExpand($node, $depth),
            'date' => self::renderDate($node),
            default => self::renderInlineChildren($node),
        };
    }

    /**
     * Render a node's children as block-level content joined by blank lines.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderBlockChildren(array $node, int $depth): string
    {
        $blocks = [];

        foreach (self::childNodes($node) as $child) {
            $rendered = self::renderNode($child, $depth);

            if ($rendered !== '') {
                $blocks[] = $rendered;
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Render a node's children as inline content joined without separators.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderInlineChildren(array $node): string
    {
        $text = '';

        foreach (self::childNodes($node) as $child) {
            $text .= self::renderNode($child);
        }

        return $text;
    }

    /**
     * Render a text node, applying its marks.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderText(array $node): string
    {
        $text = (string) ($node['text'] ?? '');
        $marks = $node['marks'] ?? [];

        if (!is_array($marks)) {
            return $text;
        }

        foreach ($marks as $mark) {
            if (!is_array($mark)) {
                continue;
            }

            $text = match ($mark['type'] ?? null) {
                'strong' => "**{$text}**",
                'em' => "*{$text}*",
                'code' => "`{$text}`",
                'strike' => "~~{$text}~~",
                'link' => self::applyLink($text, $mark),
                default => $text,
            };
        }

        return $text;
    }

    /**
     * Wrap text in a Markdown link when the mark carries an href.
     *
     * @param  array<string, mixed>  $mark
     */
    private static function applyLink(string $text, array $mark): string
    {
        $href = (string) data_get($mark, 'attrs.href', '');

        if ($href === '') {
            return $text;
        }

        return "[{$text}]({$href})";
    }

    /**
     * Render a heading node (`# ` repeated per level).
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderHeading(array $node): string
    {
        $level = (int) data_get($node, 'attrs.level', 1);
        $level = max(1, min(6, $level));

        return str_repeat('#', $level).' '.self::renderInlineChildren($node);
    }

    /**
     * Render a bullet or ordered list, indenting nested lists.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderList(array $node, int $depth, bool $ordered): string
    {
        $lines = [];
        $number = 1;
        $indent = str_repeat('  ', $depth);

        foreach (self::childNodes($node) as $item) {
            if (($item['type'] ?? null) !== 'listItem') {
                continue;
            }

            $marker = $ordered ? "{$number}. " : '- ';
            $itemBlocks = [];
            $nestedLists = [];

            foreach (self::childNodes($item) as $child) {
                $childType = $child['type'] ?? null;

                if ($childType === 'bulletList' || $childType === 'orderedList') {
                    $nestedLists[] = self::renderList($child, $depth + 1, $childType === 'orderedList');

                    continue;
                }

                $rendered = self::renderNode($child, $depth);

                if ($rendered !== '') {
                    $itemBlocks[] = $rendered;
                }
            }

            $lines[] = $indent.$marker.mb_trim(implode(' ', $itemBlocks));

            foreach ($nestedLists as $nestedList) {
                if ($nestedList !== '') {
                    $lines[] = $nestedList;
                }
            }

            $number++;
        }

        return implode("\n", $lines);
    }

    /**
     * Render a fenced code block, using the language attribute when present.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderCodeBlock(array $node): string
    {
        $language = (string) data_get($node, 'attrs.language', '');

        return "```{$language}\n".self::plainText($node)."\n```";
    }

    /**
     * Render a blockquote or panel by prefixing each line with `> `.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderBlockquote(array $node, int $depth): string
    {
        return self::prefixLines(self::renderBlockChildren($node, $depth), '> ');
    }

    /**
     * Render a mention as `@displayName`, avoiding a doubled leading `@`.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderMention(array $node): string
    {
        $text = (string) data_get($node, 'attrs.text', '');

        return '@'.mb_ltrim($text, '@');
    }

    /**
     * Render an emoji from its text or short name.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderEmoji(array $node): string
    {
        return (string) (data_get($node, 'attrs.text') ?? data_get($node, 'attrs.shortName', ''));
    }

    /**
     * Render a simple pipe table, treating the first row as the header.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderTable(array $node): string
    {
        $lines = [];
        $isFirstRow = true;

        foreach (self::childNodes($node) as $row) {
            if (($row['type'] ?? null) !== 'tableRow') {
                continue;
            }

            $cells = [];

            foreach (self::childNodes($row) as $cell) {
                if (!in_array($cell['type'] ?? null, ['tableHeader', 'tableCell'], true)) {
                    continue;
                }

                $cells[] = self::cellText($cell);
            }

            $lines[] = '| '.implode(' | ', $cells).' |';

            if ($isFirstRow) {
                $lines[] = '| '.implode(' | ', array_fill(0, max(1, count($cells)), '---')).' |';
                $isFirstRow = false;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Render a table cell's content collapsed to a single line.
     *
     * @param  array<string, mixed>  $cell
     */
    private static function cellText(array $cell): string
    {
        $text = self::renderBlockChildren($cell, 0);

        return mb_trim((string) preg_replace('/\s*\n\s*/', ' ', $text));
    }

    /**
     * Render a media container (`mediaSingle`/`mediaGroup`).
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderMediaContainer(array $node): string
    {
        $parts = [];

        foreach (self::childNodes($node) as $child) {
            $rendered = self::renderNode($child);

            if ($rendered !== '') {
                $parts[] = $rendered;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Render a media node as an image placeholder.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderMedia(array $node): string
    {
        $label = data_get($node, 'attrs.alt')
            ?? data_get($node, 'attrs.id')
            ?? 'attachment';

        return "![attachment]({$label})";
    }

    /**
     * Render a task list as Markdown checkboxes.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderTaskList(array $node): string
    {
        $lines = [];

        foreach (self::childNodes($node) as $item) {
            if (($item['type'] ?? null) !== 'taskItem') {
                continue;
            }

            $lines[] = self::renderTaskItem($item);
        }

        return implode("\n", $lines);
    }

    /**
     * Render a single task item as a checked or unchecked checkbox.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderTaskItem(array $node): string
    {
        $box = data_get($node, 'attrs.state') === 'DONE' ? '[x]' : '[ ]';

        return "- {$box} ".self::renderInlineChildren($node);
    }

    /**
     * Render an expand block as its bold title followed by its content.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderExpand(array $node, int $depth): string
    {
        $title = (string) data_get($node, 'attrs.title', '');
        $content = self::renderBlockChildren($node, $depth);

        $parts = [];

        if ($title !== '') {
            $parts[] = "**{$title}**";
        }

        if ($content !== '') {
            $parts[] = $content;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Render a date node as `Y-m-d` from its millisecond timestamp.
     *
     * @param  array<string, mixed>  $node
     */
    private static function renderDate(array $node): string
    {
        $timestamp = data_get($node, 'attrs.timestamp');

        if (!is_numeric($timestamp)) {
            return '';
        }

        return date('Y-m-d', intdiv((int) $timestamp, 1000));
    }

    /**
     * Concatenate the raw (unmarked) text of a node and its descendants.
     *
     * @param  array<string, mixed>  $node
     */
    private static function plainText(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return (string) ($node['text'] ?? '');
        }

        $text = '';

        foreach (self::childNodes($node) as $child) {
            $text .= self::plainText($child);
        }

        return $text;
    }

    /**
     * Prefix every line of the given text with the given string.
     */
    private static function prefixLines(string $text, string $prefix): string
    {
        $lines = array_map(
            static fn (string $line): string => $line === '' ? mb_rtrim($prefix) : $prefix.$line,
            explode("\n", $text),
        );

        return implode("\n", $lines);
    }

    /**
     * Return a node's child nodes as an array of arrays.
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

    /**
     * Collapse excess blank lines and trim the result.
     */
    private static function normalize(string $markdown): string
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $markdown = (string) preg_replace('/\n{3,}/', "\n\n", $markdown);

        return mb_trim($markdown);
    }
}
