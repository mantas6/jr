<?php

declare(strict_types=1);

namespace App\Services\Jira;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

class JiraInlineMacroFormatter
{
    /**
     * Inline style for issue-type icons and emoticons. The panel's CSS reset
     * makes every `<img>` a block element, which pushes each icon onto its
     * own line, so it is restored to an inline, text-sized image here.
     */
    private const ICON_STYLE = 'display: inline; width: auto; height: 1.25em; margin: 0 0.25em 0 0; vertical-align: text-bottom;';

    /**
     * Inline style for Jira status lozenges, rendered as a small badge that
     * adapts to the surrounding text colour (light and dark mode).
     */
    private const LOZENGE_STYLE = 'display: inline; padding: 0.0625em 0.375em; border-radius: 0.25em; font-size: 0.75em; font-weight: 600; text-transform: uppercase; white-space: nowrap; background-color: color-mix(in oklab, currentColor 12%, transparent);';

    /**
     * Keeps an issue macro's icon, key and status together on one line.
     */
    private const MACRO_STYLE = 'white-space: nowrap;';

    /**
     * Matches markup that needs reformatting, so other HTML is returned as-is.
     */
    private const MARKERS_PATTERN = '/jira-issue-macro|aui-lozenge|class=["\'](?:icon|emoticon)["\']/';

    /**
     * Make Jira's rendered inline macros flow with the surrounding text.
     *
     * Jira renders issue links (`SUPER-123`, inline cards) as an indented,
     * multi-line `jira-issue-macro` block with an issue-type icon, the key and
     * a status lozenge, and emoji as `<img class="emoticon">`. Inside the
     * panel's prose styles those icons become block elements, breaking each
     * card over several lines. This strips the template whitespace around and
     * inside each macro and styles icons and lozenges to render inline.
     */
    public static function format(string $html): string
    {
        if (preg_match(self::MARKERS_PATTERN, $html) !== 1) {
            return $html;
        }

        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            LIBXML_NOERROR,
        );

        $body = $document->body;

        if ($body === null) {
            return $html;
        }

        foreach ($body->querySelectorAll('.jira-issue-macro') as $macro) {
            self::formatIssueMacro($macro);
        }

        foreach ($body->querySelectorAll('img.icon, img.emoticon') as $icon) {
            $icon->setAttribute('style', self::ICON_STYLE);
        }

        foreach ($body->querySelectorAll('.aui-lozenge') as $lozenge) {
            $lozenge->setAttribute('style', self::LOZENGE_STYLE);
        }

        return $body->innerHTML;
    }

    /**
     * Collapse an issue macro's template whitespace so its icon, key and
     * status render as a single inline unit next to the surrounding text.
     */
    private static function formatIssueMacro(Element $macro): void
    {
        self::trimTemplateWhitespace($macro->previousSibling, trailing: true);
        self::trimTemplateWhitespace($macro->nextSibling, trailing: false);

        self::removeWhitespaceTextNodes($macro);

        $macro->setAttribute('style', self::MACRO_STYLE);

        foreach ($macro->querySelectorAll('.aui-lozenge') as $lozenge) {
            $lozenge->before(' ');
        }
    }

    /**
     * Remove whitespace-only text nodes inside the macro and trim the rest,
     * so links do not underline stray indentation.
     */
    private static function removeWhitespaceTextNodes(Node $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof Text) {
                $text = mb_trim($child->data);

                if ($text === '') {
                    $child->remove();
                } else {
                    $child->data = $text;
                }

                continue;
            }

            self::removeWhitespaceTextNodes($child);
        }
    }

    /**
     * Strip the newline-led indentation Jira's template places beside a
     * macro, keeping any real space that belongs to the surrounding text.
     */
    private static function trimTemplateWhitespace(?Node $sibling, bool $trailing): void
    {
        if (!$sibling instanceof Text) {
            return;
        }

        $pattern = $trailing ? '/\n\s*$/' : '/^\s*\n/';

        $sibling->data = (string) preg_replace($pattern, '', $sibling->data);
    }
}
