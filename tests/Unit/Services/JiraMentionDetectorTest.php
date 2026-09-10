<?php

use App\Services\Jira\JiraMentionDetector;

/**
 * Build an ADF paragraph node containing a mention of the given account id.
 *
 * @return array<string, mixed>
 */
function adfWithMention(string $accountId): array
{
    return [
        'type' => 'doc',
        'version' => 1,
        'content' => [
            [
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Hey '],
                    ['type' => 'mention', 'attrs' => ['id' => $accountId, 'text' => '@Me']],
                    ['type' => 'text', 'text' => ' please review.'],
                ],
            ],
        ],
    ];
}

test('it detects a mention of the account id nested in ADF', function () {
    expect(JiraMentionDetector::mentions(adfWithMention('acc-me'), 'acc-me'))->toBeTrue();
});

test('it ignores mentions of other accounts', function () {
    expect(JiraMentionDetector::mentions(adfWithMention('acc-someone-else'), 'acc-me'))->toBeFalse();
});

test('it returns false when there is no mention node', function () {
    $doc = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Nothing here.']]],
        ],
    ];

    expect(JiraMentionDetector::mentions($doc, 'acc-me'))->toBeFalse();
});

test('it returns false for non-array or empty account id', function () {
    expect(JiraMentionDetector::mentions(null, 'acc-me'))->toBeFalse()
        ->and(JiraMentionDetector::mentions('a string', 'acc-me'))->toBeFalse()
        ->and(JiraMentionDetector::mentions(adfWithMention('acc-me'), ''))->toBeFalse();
});

test('it walks a list of comment bodies', function () {
    $comments = [
        ['body' => ['type' => 'doc', 'content' => [['type' => 'text', 'text' => 'no mention']]]],
        ['body' => adfWithMention('acc-me')],
    ];

    expect(JiraMentionDetector::mentions($comments, 'acc-me'))->toBeTrue();
});
