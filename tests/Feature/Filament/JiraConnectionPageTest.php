<?php

use App\Filament\Pages\JiraConnection;
use App\Models\User;
use Filament\Auth\Pages\Register;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('guests are redirected to login', function () {
    $this->get(JiraConnection::getUrl())
        ->assertRedirect('/admin/login');
});

test('an authenticated user can render the page', function () {
    $this->actingAs(User::factory()->create());

    livewire(JiraConnection::class)
        ->assertOk();
});

test('a guest can register and lands authenticated', function () {
    $this->get('/admin/register')->assertOk();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $this->assertAuthenticated();
    expect(User::where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('the form validates the credentials', function () {
    Http::fake();

    $this->actingAs(User::factory()->create());

    livewire(JiraConnection::class)
        ->fillForm([
            'jira_site_url' => 'not-a-url',
            'jira_email' => 'not-an-email',
            'jira_api_token' => 'token',
            'jira_project_key' => 'has spaces',
        ])
        ->call('save')
        ->assertHasFormErrors([
            'jira_site_url' => 'url',
            'jira_email' => 'email',
            'jira_project_key' => 'alpha_dash',
        ]);

    Http::assertNothingSent();
});

test('saving verifies the credentials and persists the connection', function () {
    Http::fake([
        '*/rest/api/3/myself' => Http::response([
            'accountId' => 'acc-123',
            'displayName' => 'Jane Jira',
        ]),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(JiraConnection::class)
        ->fillForm([
            'jira_site_url' => 'https://acme.atlassian.net',
            'jira_email' => 'jane@acme.com',
            'jira_api_token' => 'secret-token',
            'jira_project_key' => 'proj',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Connected to Jira as Jane Jira');

    $user->refresh();

    expect($user->jira_site_url)->toBe('https://acme.atlassian.net')
        ->and($user->jira_email)->toBe('jane@acme.com')
        ->and($user->jira_api_token)->toBe('secret-token')
        ->and($user->jira_project_key)->toBe('PROJ')
        ->and($user->jira_account_id)->toBe('acc-123')
        ->and($user->jira_connected_at)->not->toBeNull()
        ->and($user->hasJiraConnection())->toBeTrue();
});

test('saving refuses invalid credentials and persists nothing', function () {
    Http::fake([
        '*/rest/api/3/myself' => Http::response(['errorMessages' => ['nope']], 401),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(JiraConnection::class)
        ->fillForm([
            'jira_site_url' => 'https://acme.atlassian.net',
            'jira_email' => 'jane@acme.com',
            'jira_api_token' => 'bad-token',
            'jira_project_key' => 'PROJ',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Could not connect to Jira');

    $user->refresh();

    expect($user->jira_connected_at)->toBeNull()
        ->and($user->jira_site_url)->toBeNull()
        ->and($user->jira_account_id)->toBeNull()
        ->and($user->hasJiraConnection())->toBeFalse();
});

test('the api token is encrypted at rest but readable through the model', function () {
    Http::fake([
        '*/rest/api/3/myself' => Http::response([
            'accountId' => 'acc-123',
            'displayName' => 'Jane Jira',
        ]),
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(JiraConnection::class)
        ->fillForm([
            'jira_site_url' => 'https://acme.atlassian.net',
            'jira_email' => 'jane@acme.com',
            'jira_api_token' => 'plaintext-token',
            'jira_project_key' => 'PROJ',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $raw = DB::table('users')->where('id', $user->id)->value('jira_api_token');

    expect($raw)->not->toBe('plaintext-token')
        ->and($user->fresh()->jira_api_token)->toBe('plaintext-token');
});

test('re-saving with a blank token keeps the existing token', function () {
    Http::fake([
        '*/rest/api/3/myself' => Http::response([
            'accountId' => 'acc-456',
            'displayName' => 'Jane Jira',
        ]),
    ]);

    $user = User::factory()->withJiraConnection()->create([
        'jira_api_token' => 'original-token',
    ]);
    $this->actingAs($user);

    livewire(JiraConnection::class)
        ->fillForm([
            'jira_site_url' => 'https://acme.atlassian.net',
            'jira_email' => 'jane@acme.com',
            'jira_api_token' => '',
            'jira_project_key' => 'PROJ',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->jira_api_token)->toBe('original-token');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Basic '.base64_encode('jane@acme.com:original-token'));
    });
});

test('disconnecting clears every jira field', function () {
    $user = User::factory()->withJiraConnection()->create();
    $this->actingAs($user);

    livewire(JiraConnection::class)
        ->callAction('disconnect')
        ->assertNotified('Disconnected from Jira');

    $user->refresh();

    expect($user->jira_site_url)->toBeNull()
        ->and($user->jira_email)->toBeNull()
        ->and($user->jira_api_token)->toBeNull()
        ->and($user->jira_account_id)->toBeNull()
        ->and($user->jira_project_key)->toBeNull()
        ->and($user->jira_connected_at)->toBeNull()
        ->and($user->jira_last_synced_at)->toBeNull()
        ->and($user->jira_last_sync_error)->toBeNull()
        ->and($user->hasJiraConnection())->toBeFalse();
});
