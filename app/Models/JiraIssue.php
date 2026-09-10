<?php

namespace App\Models;

use Database\Factories\JiraIssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'jira_created_at' => 'datetime',
            'jira_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
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
}
