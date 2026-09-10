<?php

use App\Services\Jira\JiraAttachmentProxy;

test('rewriteHtml proxies absolute attachment urls on the jira site', function () {
    $html = '<p><img src="https://example.atlassian.net/rest/api/3/attachment/content/134715" alt="shot"></p>';

    $result = JiraAttachmentProxy::rewriteHtml($html, 'https://example.atlassian.net');

    $expected = route('jira.attachment', ['path' => '/rest/api/3/attachment/content/134715']);

    expect($result)->toContain('src="'.$expected.'"')
        ->and($result)->not->toContain('example.atlassian.net/rest/api/3/attachment');
});

test('rewriteHtml proxies site-relative attachment urls', function () {
    $html = '<img src="/secure/attachment/134715/shot.png">';

    $result = JiraAttachmentProxy::rewriteHtml($html, 'https://example.atlassian.net/');

    expect($result)->toContain('src="'.route('jira.attachment', ['path' => '/secure/attachment/134715/shot.png']).'"');
});

test('rewriteHtml rewrites attachment anchor hrefs too', function () {
    $html = '<a href="https://example.atlassian.net/rest/api/3/attachment/content/999">file.png</a>';

    $result = JiraAttachmentProxy::rewriteHtml($html, 'https://example.atlassian.net');

    expect($result)->toContain('href="'.route('jira.attachment', ['path' => '/rest/api/3/attachment/content/999']).'"');
});

test('rewriteHtml leaves non-attachment jira links untouched', function () {
    $html = '<a href="https://example.atlassian.net/browse/PROJ-1">PROJ-1</a>';

    $result = JiraAttachmentProxy::rewriteHtml($html, 'https://example.atlassian.net');

    expect($result)->toBe($html);
});

test('rewriteHtml leaves external image urls untouched', function () {
    $html = '<img src="https://cdn.example.com/logo.png">';

    $result = JiraAttachmentProxy::rewriteHtml($html, 'https://example.atlassian.net');

    expect($result)->toBe($html);
});

test('rewriteHtml returns the html unchanged when no site url is given', function () {
    $html = '<img src="https://example.atlassian.net/rest/api/3/attachment/content/1">';

    expect(JiraAttachmentProxy::rewriteHtml($html, null))->toBe($html)
        ->and(JiraAttachmentProxy::rewriteHtml($html, '  '))->toBe($html);
});

test('isAllowedPath only permits known attachment prefixes', function () {
    expect(JiraAttachmentProxy::isAllowedPath('/rest/api/3/attachment/content/1'))->toBeTrue()
        ->and(JiraAttachmentProxy::isAllowedPath('/rest/api/2/attachment/content/1'))->toBeTrue()
        ->and(JiraAttachmentProxy::isAllowedPath('/secure/attachment/1/a.png'))->toBeTrue()
        ->and(JiraAttachmentProxy::isAllowedPath('/secure/thumbnail/1/a.png'))->toBeTrue()
        ->and(JiraAttachmentProxy::isAllowedPath('/rest/api/3/issue/PROJ-1'))->toBeFalse()
        ->and(JiraAttachmentProxy::isAllowedPath('/myself'))->toBeFalse()
        ->and(JiraAttachmentProxy::isAllowedPath('rest/api/3/attachment/content/1'))->toBeFalse();
});
