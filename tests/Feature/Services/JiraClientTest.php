<?php

use App\Models\User;
use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function jiraUser(): User
{
    return User::factory()->withJiraConnection()->create([
        'jira_site_url' => 'https://example.atlassian.net',
        'jira_email' => 'me@example.com',
        'jira_api_token' => 'secret-token',
        'jira_project_key' => 'PROJ',
    ]);
}

test('requests use basic auth and the configured base url', function () {
    Http::fake([
        '*' => Http::response(['accountId' => 'abc', 'displayName' => 'Me']),
    ]);

    $result = JiraClient::forUser(jiraUser())->me();

    expect($result)->toBe(['accountId' => 'abc', 'displayName' => 'Me']);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://example.atlassian.net/rest/api/3/myself'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('me@example.com:secret-token'));
    });
});

test('searchIssues sends jql, fields and nextPageToken query params and returns the decoded body', function () {
    $body = ['issues' => [['id' => '1']], 'nextPageToken' => 'next', 'isLast' => false];

    Http::fake([
        '*' => Http::response($body),
    ]);

    $result = JiraClient::forUser(jiraUser())->searchIssues('project = "PROJ"', 'cursor-1');

    expect($result)->toBe($body);

    Http::assertSent(function (Request $request) {
        return str_starts_with($request->url(), 'https://example.atlassian.net/rest/api/3/search/jql')
            && $request['jql'] === 'project = "PROJ"'
            && $request['fields'] === 'summary,status,issuetype,priority,assignee,reporter,created,updated'
            && $request['maxResults'] === 100
            && $request['nextPageToken'] === 'cursor-1';
    });
});

test('searchIssues omits nextPageToken when null', function () {
    Http::fake(['*' => Http::response(['issues' => []])]);

    JiraClient::forUser(jiraUser())->searchIssues('project = "PROJ"');

    Http::assertSent(fn (Request $request) => ! isset($request['nextPageToken']));
});

test('searchIssues appends extra fields to the requested field list', function () {
    Http::fake(['*' => Http::response(['issues' => []])]);

    JiraClient::forUser(jiraUser())->searchIssues('project = "PROJ"', null, ['customfield_10020']);

    Http::assertSent(function (Request $request) {
        return $request['fields'] === 'summary,status,issuetype,priority,assignee,reporter,created,updated,customfield_10020';
    });
});

test('sprintFieldId resolves the sprint custom field id from the fields endpoint', function () {
    Http::fake([
        '*/rest/api/3/field' => Http::response([
            ['id' => 'summary', 'schema' => ['type' => 'string']],
            ['id' => 'customfield_10020', 'schema' => ['custom' => 'com.pyxis.greenhopper.jira:gh-sprint']],
        ]),
    ]);

    $client = JiraClient::forUser(jiraUser());

    expect($client->sprintFieldId())->toBe('customfield_10020')
        ->and($client->sprintFieldId())->toBe('customfield_10020');

    // The result is memoized, so the endpoint is only hit once.
    Http::assertSentCount(1);
});

test('sprintFieldId returns null when the instance has no sprint field', function () {
    Http::fake([
        '*/rest/api/3/field' => Http::response([
            ['id' => 'summary', 'schema' => ['type' => 'string']],
        ]),
    ]);

    expect(JiraClient::forUser(jiraUser())->sprintFieldId())->toBeNull();
});

test('sprintFieldId returns null instead of failing when the lookup errors', function () {
    Http::fake([
        '*/rest/api/3/field' => Http::response(['errorMessages' => ['nope']], 403),
    ]);

    expect(JiraClient::forUser(jiraUser())->sprintFieldId())->toBeNull();
});

test('transitionIssue posts the transition body', function () {
    Http::fake(['*' => Http::response(null, 204)]);

    JiraClient::forUser(jiraUser())->transitionIssue('PROJ-1', '31');

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://example.atlassian.net/rest/api/3/issue/PROJ-1/transitions'
            && $request['transition'] === ['id' => '31'];
    });
});

test('assignIssue with null sends an accountId of null', function () {
    Http::fake(['*' => Http::response(null, 204)]);

    JiraClient::forUser(jiraUser())->assignIssue('PROJ-1', null);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'PUT'
            && $request->url() === 'https://example.atlassian.net/rest/api/3/issue/PROJ-1/assignee'
            && array_key_exists('accountId', $request->data())
            && $request['accountId'] === null;
    });
});

test('searchAssignableUsers sends project and query params', function () {
    Http::fake(['*' => Http::response([['accountId' => 'u1']])]);

    $result = JiraClient::forUser(jiraUser())->searchAssignableUsers('PROJ', 'jane');

    expect($result)->toBe([['accountId' => 'u1']]);

    Http::assertSent(function (Request $request) {
        return str_starts_with($request->url(), 'https://example.atlassian.net/rest/api/3/user/assignable/search')
            && $request['project'] === 'PROJ'
            && $request['query'] === 'jane';
    });
});

test('getTransitions returns the transitions array', function () {
    Http::fake(['*' => Http::response(['transitions' => [['id' => '11', 'name' => 'Done']]])]);

    $result = JiraClient::forUser(jiraUser())->getTransitions('PROJ-1');

    expect($result)->toBe([['id' => '11', 'name' => 'Done']]);
});

test('a 401 response throws a readable JiraApiException', function () {
    Http::fake(['*' => Http::response(['errorMessages' => ['nope']], 401)]);

    JiraClient::forUser(jiraUser())->me();
})->throws(JiraApiException::class, 'Jira rejected the credentials (401). Check your email and API token.');

test('a 400 response includes Jira error messages', function () {
    Http::fake(['*' => Http::response(['errorMessages' => ['JQL is invalid'], 'errors' => []], 400)]);

    try {
        JiraClient::forUser(jiraUser())->searchIssues('bad jql');
        $this->fail('Expected JiraApiException was not thrown.');
    } catch (JiraApiException $exception) {
        expect($exception->getMessage())->toContain('JQL is invalid')
            ->and($exception->status)->toBe(400);
    }
});

test('a connection exception is wrapped in a JiraApiException', function () {
    Http::fake(function () {
        throw new ConnectionException('timeout');
    });

    try {
        JiraClient::forUser(jiraUser())->me();
        $this->fail('Expected JiraApiException was not thrown.');
    } catch (JiraApiException $exception) {
        expect($exception->getMessage())->toContain('Could not reach Jira at https://example.atlassian.net')
            ->and($exception->status)->toBe(0);
    }
});
