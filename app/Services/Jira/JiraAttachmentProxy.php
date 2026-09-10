<?php

declare(strict_types=1);

namespace App\Services\Jira;

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
