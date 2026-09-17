<?php

declare(strict_types=1);

namespace App\Services\Jira;

class JiraTextToAdf
{
    /**
     * Convert plain, lightly-Markdown-formatted text into an Atlassian Document
     * Format (ADF) document suitable for posting to Jira.
     *
     * Supports a pragmatic subset: blank-line-separated paragraphs (single
     * newlines become hard breaks), bullet and ordered lists, fenced code
     * blocks, ATX headings (levels 1-3) and the inline marks strong, em, code
     * and link (including bare URLs). Nesting is intentionally not supported.
     *
     * @return array{version: int, type: string, content: list<array<string, mixed>>}
     */
    public static function convert(string $text): array
    {
        return [
            'version' => 1,
            'type' => 'doc',
            'content' => self::blocks($text),
        ];
    }

    /**
     * Parse the text into a list of top-level block nodes.
     *
     * @return list<array<string, mixed>>
     */
    private static function blocks(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $content = [];
        $count = count($lines);
        $index = 0;

        while ($index < $count) {
            $line = $lines[$index];

            if (mb_trim($line) === '') {
                $index++;

                continue;
            }

            if (preg_match('/^```(.*)$/', $line, $matches) === 1) {
                $content[] = self::consumeCodeBlock($lines, $index, mb_trim($matches[1]));

                continue;
            }

            if (preg_match('/^(#{1,3}) (.*)$/', $line, $matches) === 1) {
                $content[] = self::heading(mb_strlen($matches[1]), $matches[2]);
                $index++;

                continue;
            }

            if (preg_match('/^[-*] (.*)$/', $line) === 1) {
                $content[] = self::consumeList($lines, $index, ordered: false);

                continue;
            }

            if (preg_match('/^\d+[.)] (.*)$/', $line) === 1) {
                $content[] = self::consumeList($lines, $index, ordered: true);

                continue;
            }

            $content[] = self::consumeParagraph($lines, $index);
        }

        return $content;
    }

    /**
     * Consume a fenced code block starting at the opening fence.
     *
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private static function consumeCodeBlock(array $lines, int &$index, string $language): array
    {
        $count = count($lines);
        $index++;
        $codeLines = [];

        while ($index < $count && preg_match('/^```\s*$/', $lines[$index]) !== 1) {
            $codeLines[] = $lines[$index];
            $index++;
        }

        if ($index < $count) {
            $index++;
        }

        $code = implode("\n", $codeLines);

        $node = ['type' => 'codeBlock'];

        if ($language !== '') {
            $node['attrs'] = ['language' => $language];
        }

        $node['content'] = $code === '' ? [] : [['type' => 'text', 'text' => $code]];

        return $node;
    }

    /**
     * Consume a run of adjacent list items into a single list node.
     *
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private static function consumeList(array $lines, int &$index, bool $ordered): array
    {
        $count = count($lines);
        $pattern = $ordered ? '/^\d+[.)] (.*)$/' : '/^[-*] (.*)$/';
        $items = [];

        while ($index < $count && preg_match($pattern, $lines[$index], $matches) === 1) {
            $items[] = [
                'type' => 'listItem',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => self::inline($matches[1]),
                    ],
                ],
            ];
            $index++;
        }

        return [
            'type' => $ordered ? 'orderedList' : 'bulletList',
            'content' => $items,
        ];
    }

    /**
     * Consume a run of adjacent text lines into a paragraph, turning single
     * newlines into hard breaks.
     *
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private static function consumeParagraph(array $lines, int &$index): array
    {
        $count = count($lines);
        $content = [];
        $first = true;

        while ($index < $count && mb_trim($lines[$index]) !== '' && !self::startsBlock($lines[$index])) {
            if (!$first) {
                $content[] = ['type' => 'hardBreak'];
            }

            $content = array_merge($content, self::inline($lines[$index]));
            $first = false;
            $index++;
        }

        return [
            'type' => 'paragraph',
            'content' => $content,
        ];
    }

    /**
     * Determine whether a line begins a non-paragraph block.
     */
    private static function startsBlock(string $line): bool
    {
        return preg_match('/^```/', $line) === 1
            || preg_match('/^#{1,3} /', $line) === 1
            || preg_match('/^[-*] /', $line) === 1
            || preg_match('/^\d+[.)] /', $line) === 1;
    }

    /**
     * Build a heading node.
     *
     * @return array<string, mixed>
     */
    private static function heading(int $level, string $text): array
    {
        return [
            'type' => 'heading',
            'attrs' => ['level' => $level],
            'content' => self::inline($text),
        ];
    }

    /**
     * Parse a single line of text into inline ADF nodes, applying marks.
     *
     * @return list<array<string, mixed>>
     */
    private static function inline(string $text): array
    {
        $nodes = [];
        $buffer = '';
        $length = mb_strlen($text);
        $index = 0;

        while ($index < $length) {
            $rest = mb_substr($text, $index);

            if (preg_match('/^`([^`]+)`/', $rest, $matches) === 1) {
                self::flush($nodes, $buffer);
                $nodes[] = self::marked($matches[1], [['type' => 'code']]);
                $index += mb_strlen($matches[0]);

                continue;
            }

            if (preg_match('/^\[([^\]]+)\]\(([^)\s]+)\)/', $rest, $matches) === 1) {
                self::flush($nodes, $buffer);
                $nodes[] = self::marked($matches[1], [['type' => 'link', 'attrs' => ['href' => $matches[2]]]]);
                $index += mb_strlen($matches[0]);

                continue;
            }

            if (preg_match('/^\*\*(.+?)\*\*/', $rest, $matches) === 1) {
                self::flush($nodes, $buffer);
                $nodes[] = self::marked($matches[1], [['type' => 'strong']]);
                $index += mb_strlen($matches[0]);

                continue;
            }

            if (preg_match('/^\*(.+?)\*/', $rest, $matches) === 1 || preg_match('/^_(.+?)_/', $rest, $matches) === 1) {
                self::flush($nodes, $buffer);
                $nodes[] = self::marked($matches[1], [['type' => 'em']]);
                $index += mb_strlen($matches[0]);

                continue;
            }

            if (preg_match('/^(https?:\/\/[^\s]+)/', $rest, $matches) === 1) {
                self::flush($nodes, $buffer);
                $nodes[] = self::marked($matches[1], [['type' => 'link', 'attrs' => ['href' => $matches[1]]]]);
                $index += mb_strlen($matches[0]);

                continue;
            }

            $buffer .= mb_substr($text, $index, 1);
            $index++;
        }

        self::flush($nodes, $buffer);

        return $nodes;
    }

    /**
     * Build a marked text node.
     *
     * @param  list<array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private static function marked(string $text, array $marks): array
    {
        return [
            'type' => 'text',
            'text' => $text,
            'marks' => $marks,
        ];
    }

    /**
     * Flush any buffered plain text into a text node.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private static function flush(array &$nodes, string &$buffer): void
    {
        if ($buffer !== '') {
            $nodes[] = ['type' => 'text', 'text' => $buffer];
            $buffer = '';
        }
    }
}
