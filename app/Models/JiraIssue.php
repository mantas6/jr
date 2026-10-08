<?php

namespace App\Models;

use App\Services\Jira\JiraDuration;
use App\Services\Jira\JiraPullRequestState;
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
 * @property bool|null $in_active_sprint
 * @property int|null $original_estimate_seconds
 * @property JiraPullRequestState|null $pr_state
 * @property int|null $pr_count
 * @property bool $pr_approved
 * @property bool $is_important
 * @property Carbon|null $concerning_since
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $last_viewed_at
 * @property Carbon|null $assigned_to_me_at
 * @property bool $mentions_me
 * @property Carbon|null $mentions_scanned_at
 * @property Carbon|null $last_mentioned_at
 * @property Carbon|null $last_commented_at
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
    'in_active_sprint',
    'original_estimate_seconds',
    'pr_state',
    'pr_count',
    'pr_approved',
    'is_important',
    'concerning_since',
    'snoozed_until',
    'dismissed_at',
    'last_viewed_at',
    'assigned_to_me_at',
    'mentions_me',
    'mentions_scanned_at',
    'last_mentioned_at',
    'last_commented_at',
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
     * Determine whether an assignee change newly assigns the issue to the user:
     * the incoming assignee is the user's Jira account and the previous one was
     * someone else (or nobody). Always false when the user has no Jira account id.
     */
    public static function becameAssignedToMe(?string $previousAssignee, ?string $incomingAssignee, User $user): bool
    {
        $accountId = $user->jira_account_id;

        if (blank($accountId)) {
            return false;
        }

        return $incomingAssignee === $accountId && $previousAssignee !== $accountId;
    }

    /**
     * Determine whether the issue is currently snoozed: it has a snooze time set
     * and that time is still in the future. An expired snooze counts as not
     * snoozed.
     */
    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    /**
     * Determine whether the issue is unread: it has never been viewed, or Jira
     * reports activity newer than the last view. Mirrors {@see scopeUnread()}.
     */
    public function isUnread(): bool
    {
        return $this->last_viewed_at === null || $this->jira_updated_at->gt($this->last_viewed_at);
    }

    /**
     * The original estimate formatted Jira-style (e.g. `2w 1d 3h 30m`), or null
     * when the issue has no estimate.
     */
    public function formattedOriginalEstimate(): ?string
    {
        return JiraDuration::format($this->original_estimate_seconds);
    }

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
     * Scope the query to issues that currently need the user's attention and are
     * not snoozed. Composes {@see scopeNotSnoozed()} with {@see scopeConcerning()}.
     *
     * @param  Builder<JiraIssue>  $query
     * @return Builder<JiraIssue>
     */
    public function scopeConcerningFor(Builder $query, User $user): Builder
    {
        return $query->notSnoozed()->concerning($user);
    }

    /**
     * Scope the query to issues that need the user's attention, regardless of
     * snooze state.
     *
     * A task is "concerning" when it has been pinned to the list (starred at some
     * point and not dismissed since), or it is assigned to the user / mentions
     * the user and has not been dismissed, or was dismissed and the user has
     * since been newly assigned or newly mentioned in a comment. Other Jira
     * activity does not re-surface a dismissed task. Unstarring a pinned task
     * keeps it on the list until it is explicitly dismissed.
     *
     * @param  Builder<JiraIssue>  $query
     * @return Builder<JiraIssue>
     */
    public function scopeConcerning(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->whereNotNull('concerning_since')
                ->orWhere(function (Builder $query) use ($user): void {
                    $query->where(function (Builder $query) use ($user): void {
                        if (filled($user->jira_account_id)) {
                            $query->where('assignee_account_id', $user->jira_account_id);
                        }

                        $query->orWhere('mentions_me', true);
                    })->where(function (Builder $query): void {
                        $query->whereNull('dismissed_at')
                            ->orWhereColumn('assigned_to_me_at', '>', 'dismissed_at')
                            ->orWhereColumn('last_mentioned_at', '>', 'dismissed_at');
                    });
                });
        });
    }

    /**
     * Scope the query to issues that are not currently snoozed.
     *
     * @param  Builder<JiraIssue>  $query
     * @return Builder<JiraIssue>
     */
    public function scopeNotSnoozed(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('snoozed_until')
                ->orWhere('snoozed_until', '<=', now());
        });
    }

    /**
     * Scope the query to issues that are currently snoozed.
     *
     * @param  Builder<JiraIssue>  $query
     * @return Builder<JiraIssue>
     */
    public function scopeSnoozed(Builder $query): Builder
    {
        return $query->whereNotNull('snoozed_until')
            ->where('snoozed_until', '>', now());
    }

    /**
     * Scope the query to unread issues: never viewed, or updated in Jira since
     * they were last viewed.
     *
     * @param  Builder<JiraIssue>  $query
     * @return Builder<JiraIssue>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('last_viewed_at')
                ->orWhereColumn('jira_updated_at', '>', 'last_viewed_at');
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
            'in_active_sprint' => 'boolean',
            'original_estimate_seconds' => 'integer',
            'pr_state' => JiraPullRequestState::class,
            'pr_count' => 'integer',
            'pr_approved' => 'boolean',
            'is_important' => 'boolean',
            'concerning_since' => 'datetime',
            'snoozed_until' => 'datetime',
            'dismissed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'assigned_to_me_at' => 'datetime',
            'mentions_me' => 'boolean',
            'mentions_scanned_at' => 'datetime',
            'last_mentioned_at' => 'datetime',
            'last_commented_at' => 'datetime',
            'raw' => 'array',
            'jira_created_at' => 'datetime',
            'jira_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
