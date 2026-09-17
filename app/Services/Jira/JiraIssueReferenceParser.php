<?php

declare(strict_types=1);

namespace App\Services\Jira;

/**
 * Parses free-form user input (Jira links, task keys, or bare issue numbers)
 * into a normalized list of unique, upper-cased Jira issue keys.
 */
class JiraIssueReferenceParser
{
    /**
     * Matches a Jira issue key such as `PROJ-123`, case-insensitively.
     */
    private const KEY_PATTERN = '/[A-Za-z][A-Za-z0-9_]+-\d+/';

    /**
     * Matches a bare issue number, optionally prefixed with `#`.
     */
    private const NUMBER_PATTERN = '/^#?(\d+)$/';

    /**
     * Extract unique, upper-cased Jira issue keys from the input, in input order.
     *
     * Each whitespace/comma-separated token is inspected: any embedded issue
     * keys are extracted (a token may hold several, e.g. `PROJ-1 and PROJ-2`),
     * while a purely numeric token (optionally `#`-prefixed) becomes
     * `{defaultProjectKey}-{number}` when a default project key is provided.
     *
     * @return list<string>
     */
    public static function parse(string $input, ?string $defaultProjectKey): array
    {
        $default = $defaultProjectKey !== null ? mb_strtoupper($defaultProjectKey) : null;

        $tokens = preg_split('/[\s,]+/', mb_trim($input)) ?: [];

        $keys = [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (preg_match_all(self::KEY_PATTERN, $token, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $keys[] = mb_strtoupper($match);
                }

                continue;
            }

            if ($default !== null && preg_match(self::NUMBER_PATTERN, $token, $number) === 1) {
                $keys[] = $default.'-'.$number[1];
            }
        }

        return array_values(array_unique($keys));
    }
}
