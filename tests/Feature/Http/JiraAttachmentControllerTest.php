<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function attachmentUser(): User
{
    return User::factory()->withJiraConnection()->create([
        'jira_site_url' => 'https://example.atlassian.net',
        'jira_email' => 'me@example.com',
        'jira_api_token' => 'secret-token',
        'jira_project_key' => 'PROJ',
    ]);
}

test('it proxies an attachment using the user jira credentials', function () {
    Http::fake([
        '*' => Http::response('binary-image-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $this->actingAs(attachmentUser())
        ->get(route('jira.attachment', ['path' => '/rest/api/3/attachment/content/134715']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertSee('binary-image-bytes');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://example.atlassian.net/rest/api/3/attachment/content/134715'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('me@example.com:secret-token')));
});

test('it rejects paths outside the attachment allowlist', function () {
    Http::fake();

    $this->actingAs(attachmentUser())
        ->get(route('jira.attachment', ['path' => '/rest/api/3/issue/PROJ-1']))
        ->assertNotFound();

    Http::assertNothingSent();
});

test('it returns 404 when the user has no jira connection', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->get(route('jira.attachment', ['path' => '/rest/api/3/attachment/content/1']))
        ->assertNotFound();

    Http::assertNothingSent();
});

test('it returns 404 when jira responds with an error', function () {
    Http::fake([
        '*' => Http::response('nope', 403),
    ]);

    $this->actingAs(attachmentUser())
        ->get(route('jira.attachment', ['path' => '/rest/api/3/attachment/content/1']))
        ->assertNotFound();
});

test('it requires authentication', function () {
    $this->get(route('jira.attachment', ['path' => '/rest/api/3/attachment/content/1']))
        ->assertRedirect();
});
