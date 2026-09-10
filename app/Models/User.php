<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property string|null $jira_site_url
 * @property string|null $jira_email
 * @property string|null $jira_api_token
 * @property string|null $jira_account_id
 * @property string|null $jira_project_key
 * @property Carbon|null $jira_connected_at
 * @property Carbon|null $jira_last_synced_at
 * @property Carbon|null $jira_last_full_synced_at
 * @property string|null $jira_last_sync_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'password',
    'jira_site_url',
    'jira_email',
    'jira_api_token',
    'jira_account_id',
    'jira_project_key',
    'jira_connected_at',
    'jira_last_synced_at',
    'jira_last_full_synced_at',
    'jira_last_sync_error',
])]
#[Hidden(['password', 'remember_token', 'jira_api_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'jira_api_token' => 'encrypted',
            'jira_connected_at' => 'datetime',
            'jira_last_synced_at' => 'datetime',
            'jira_last_full_synced_at' => 'datetime',
        ];
    }

    /**
     * The Jira issues synced for this user.
     *
     * @return HasMany<JiraIssue, $this>
     */
    public function jiraIssues(): HasMany
    {
        return $this->hasMany(JiraIssue::class);
    }

    /**
     * Determine whether the user has a fully configured Jira connection.
     */
    public function hasJiraConnection(): bool
    {
        return filled($this->jira_site_url)
            && filled($this->jira_email)
            && filled($this->jira_api_token)
            && filled($this->jira_project_key)
            && filled($this->jira_connected_at);
    }

    /**
     * Determine whether the user can access the given Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
