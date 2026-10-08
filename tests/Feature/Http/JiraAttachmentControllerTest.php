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

test('it forwards the upstream content disposition verbatim', function () {
    $disposition = "attachment; filename=\"report.pdf\"; filename*=UTF-8''r%C3%A9port.pdf";

    Http::fake([
        '*' => Http::response('pdf-bytes', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
        ]),
    ]);

    $this->actingAs(attachmentUser())
        ->get(route('jira.attachment', ['path' => '/rest/api/3/attachment/content/123']))
        ->assertOk()
        ->assertHeader('Content-Disposition', $disposition);
});

test('it derives an inline content disposition from the filename in the path', function () {
    Http::fake([
        '*' => Http::response('pdf-bytes', 200, ['Content-Type' => 'application/pdf']),
    ]);

    $this->actingAs(attachmentUser())
        ->get(route('jira.attachment', ['path' => '/secure/attachment/123/My%20Report.pdf']))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="My Report.pdf"');
});

test('it omits the content disposition when the path carries no filename', function () {
    Http::fake([
        '*' => Http::response('binary-image-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $this->actingAs(attachmentUser())
        ->get(route('jira.attachment', ['path' => '/rest/api/3/attachment/content/123']))
        ->assertOk()
        ->assertHeaderMissing('Content-Disposition');
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
