<?php

declare(strict_types=1);

namespace App\Services\Jira;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class JiraAttachmentProxy
{
    /**
     * Path prefixes on the user's Jira instance that may be proxied. These
     * cover the endpoints Jira embeds in rendered issue/comment HTML for
     * attachments and their thumbnails. Restricting to these prefixes keeps
     * the proxy from being used to fetch arbitrary authenticated resources.
     *
     * @var list<string>
     */
    private const ALLOWED_PREFIXES = [
        '/rest/api/3/attachment/',
        '/rest/api/2/attachment/',
        '/secure/attachment/',
        '/secure/thumbnail/',
    ];

    /**
     * Rewrite `src`/`href` attributes that point at proxyable Jira attachment
     * endpoints so they load through the authenticated local proxy instead of
     * hitting Atlassian directly (which the browser cannot authenticate).
     */
    public static function rewriteHtml(string $html, ?string $jiraSiteUrl): string
    {
        if (mb_trim($html) === '' || !is_string($jiraSiteUrl) || mb_trim($jiraSiteUrl) === '') {
            return $html;
        }

        $siteUrl = mb_rtrim($jiraSiteUrl, '/');

        return (string) preg_replace_callback(
            '/\b(src|href)=(["\'])(.*?)\2/i',
            static function (array $matches) use ($siteUrl): string {
                $path = self::proxyablePath($matches[3], $siteUrl);

                if ($path === null) {
                    return $matches[0];
                }

                $url = route('jira.attachment', ['path' => $path]);

                return $matches[1].'='.$matches[2].$url.$matches[2];
            },
            $html,
        );
    }

    /**
     * Determine whether the given (already decoded) path may be proxied.
     */
    public static function isAllowedPath(string $path): bool
    {
        if (!str_starts_with($path, '/')) {
            return false;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the `Content-Disposition` header for a proxied attachment so the
     * browser keeps the original filename instead of naming it after the proxy
     * route. Jira's own header wins when present; otherwise the filename is
     * taken from the last path segment (e.g. `/secure/attachment/1/a.pdf`).
     * The disposition is `inline` so embedded images still render in-page.
     */
    public static function contentDisposition(string $path, ?string $upstreamDisposition): ?string
    {
        if (is_string($upstreamDisposition) && mb_trim($upstreamDisposition) !== '') {
            return $upstreamDisposition;
        }

        $filename = self::filenameFromPath($path);

        if ($filename === null) {
            return null;
        }

        $asciiFallback = (string) preg_replace('/[^\x20-\x7E]|%/', '_', Str::ascii($filename));

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $filename, $asciiFallback);
    }

    /**
     * Extract a filename from the last segment of an attachment path, or null
     * when the segment does not look like one (e.g. a bare numeric id).
     */
    private static function filenameFromPath(string $path): ?string
    {
        $pathOnly = (string) parse_url($path, PHP_URL_PATH);
        $segment = rawurldecode(Str::afterLast($pathOnly, '/'));
        $filename = mb_trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $segment));

        if (!str_contains($filename, '.') || preg_match('/[^\d.]/', $filename) !== 1) {
            return null;
        }

        return $filename;
    }

    /**
     * Resolve an attribute URL to a proxyable Jira path (with query string),
     * or null when it should be left untouched.
     */
    private static function proxyablePath(string $url, string $siteUrl): ?string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);

        if (str_starts_with($url, $siteUrl)) {
            $path = mb_substr($url, mb_strlen($siteUrl));
        } elseif (str_starts_with($url, '/')) {
            $path = $url;
        } else {
            return null;
        }

        return self::isAllowedPath($path) ? $path : null;
    }
}
