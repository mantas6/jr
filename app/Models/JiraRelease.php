<?php

namespace App\Models;

use App\Services\Jira\JiraReleaseStatus;
use Database\Factories\JiraReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A version of the user's configured Jira project, synced from
 * `/rest/api/3/project/{key}/versions`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $jira_id
 * @property string $name
 * @property string|null $description
 * @property bool $released
 * @property bool $archived
 * @property bool $overdue
 * @property Carbon|null $start_date
 * @property Carbon|null $release_date
 * @property string $jira_url
 * @property array<string, mixed> $raw
 * @property Carbon $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $issues_count
 * @property int|null $done_issues_count
 */
#[Fillable([
    'user_id',
    'jira_id',
    'name',
    'description',
    'released',
    'archived',
    'overdue',
    'start_date',
    'release_date',
    'jira_url',
    'raw',
    'last_synced_at',
])]
class JiraRelease extends Model
{
    /** @use HasFactory<JiraReleaseFactory> */
    use HasFactory;

    /**
     * The user that owns the release.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The issues whose Jira `fixVersions` include this release.
     *
     * @return BelongsToMany<JiraIssue, $this>
     */
    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(JiraIssue::class);
    }

    /**
     * The display status derived from the released, archived and overdue flags.
     */
    public function status(): JiraReleaseStatus
    {
        return JiraReleaseStatus::forFlags($this->released, $this->archived, $this->overdue);
    }

    /**
     * The share of the release's tasks in the Done status category (0-100), or
     * null when it has no tasks. Requires {@see scopeWithIssueProgress()}.
     */
    public function issueProgressPercentage(): ?int
    {
        $total = (int) $this->issues_count;

        if ($total === 0) {
            return null;
        }

        return (int) floor((int) $this->done_issues_count * 100 / $total);
    }

    /**
     * Eager count the release's tasks, and those in the Done status category.
     *
     * @param  Builder<JiraRelease>  $query
     * @return Builder<JiraRelease>
     */
    public function scopeWithIssueProgress(Builder $query): Builder
    {
        return $query->withCount([
            'issues',
            'issues as done_issues_count' => fn (Builder $query): Builder => $query->whereRaw('lower(status_category) = ?', ['done']),
        ]);
    }

    /**
     * Scope the query to releases that are neither released nor archived.
     *
     * @param  Builder<JiraRelease>  $query
     * @return Builder<JiraRelease>
     */
    public function scopeUnreleased(Builder $query): Builder
    {
        return $query->where('released', false)->where('archived', false);
    }

    /**
     * Scope the query to released releases that are not archived.
     *
     * @param  Builder<JiraRelease>  $query
     * @return Builder<JiraRelease>
     */
    public function scopeReleased(Builder $query): Builder
    {
        return $query->where('released', true)->where('archived', false);
    }

    /**
     * Scope the query to archived releases, released or not.
     *
     * @param  Builder<JiraRelease>  $query
     * @return Builder<JiraRelease>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('archived', true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released' => 'boolean',
            'archived' => 'boolean',
            'overdue' => 'boolean',
            'start_date' => 'date',
            'release_date' => 'date',
            'raw' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
