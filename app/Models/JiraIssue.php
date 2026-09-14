<?php

namespace App\Models;

use Database\Factories\JiraIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $jira_id
 * @property string $jira_key
 * @property string $summary
 * @property string $status
 * @property string $status_id
 * @property string $status_category
 * @property string $issue_type
 * @property string|null $priority
 * @property string|null $assignee_account_id
 * @property string|null $assignee_name
 * @property string|null $reporter_name
 * @property array<int, string>|null $sprints
 * @property bool $is_important
 * @property Carbon|null $concerning_since
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $dismissed_at
 * @property bool $mentions_me
 * @property Carbon|null $mentions_scanned_at
 * @property string $jira_url
 * @property Carbon $jira_created_at
 * @property Carbon $jira_updated_at
 * @property array<string, mixed> $raw
 * @property Carbon $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'jira_id',
    'jira_key',
    'summary',
    'status',
    'status_id',
    'status_category',
    'issue_type',
    'priority',
    'assignee_account_id',
    'assignee_name',
    'reporter_name',
    'sprints',
    'is_important',
    'concerning_since',
    'snoozed_until',
    'dismissed_at',
    'mentions_me',
    'mentions_scanned_at',
    'jira_url',
    'jira_created_at',
    'jira_updated_at',
    'raw',
    'last_synced_at',
])]
class JiraIssue extends Model
{
    /** @use HasFactory<JiraIssueFactory> */
    use HasFactory;

    /**
     * The user that owns the Jira issue.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope the query to issues that currently need the user's attention.
     *
     * A task is "concerning" when it is not snoozed and either it has been
     * pinned to the list (starred at some point and not dismissed since), or it
     * is assigned to the user / mentions the user and has been updated in Jira
     * since it was last dismissed. Unstarring a pinned task keeps it on the list
     * until the task is explicitly dismissed.
     *
     * @param  Builder<JiraIssue>  $query
     * @return Builder<JiraIssue>
     */
    public function scopeConcerningFor(Builder $query, User $user): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query->whereNull('snoozed_until')
                    ->orWhere('snoozed_until', '<=', now());
            })
            ->where(function (Builder $query) use ($user): void {
                $query->whereNotNull('concerning_since')
                    ->orWhere(function (Builder $query) use ($user): void {
                        $query->where(function (Builder $query) use ($user): void {
                            if (filled($user->jira_account_id)) {
                                $query->where('assignee_account_id', $user->jira_account_id);
                            }

                            $query->orWhere('mentions_me', true);
                        })->where(function (Builder $query): void {
                            $query->whereNull('dismissed_at')
                                ->orWhereColumn('jira_updated_at', '>', 'dismissed_at');
                        });
                    });
            });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sprints' => 'array',
            'is_important' => 'boolean',
            'concerning_since' => 'datetime',
            'snoozed_until' => 'datetime',
            'dismissed_at' => 'datetime',
            'mentions_me' => 'boolean',
            'mentions_scanned_at' => 'datetime',
            'raw' => 'array',
            'jira_created_at' => 'datetime',
            'jira_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
