<?php

declare(strict_types=1);

namespace App\Services\Jira;

use App\Models\JiraIssue;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Support\Icons\Heroicon;

/**
 * Why a task is on the concerning list, with the icon and label used to
 * display it.
 */
enum JiraIssueReason: string
{
    case Woke = 'woke';
    case Mentioned = 'mentioned';
    case Commented = 'commented';
    case Assigned = 'assigned';
    case Starred = 'starred';
    case Added = 'added';

    /**
     * Resolve the reason from the most recent trigger: a snooze expiring, a
     * comment mention, an unread comment by someone else, a new assignment or
     * a pin (star or manual add). Ties go to the earlier case. Without any
     * timestamped trigger, a star, an assignment or a mention still counts.
     */
    public static function for(JiraIssue $issue, User $user): ?self
    {
        $isUnreadComment = $issue->last_commented_at !== null
            && ($issue->last_viewed_at === null || $issue->last_commented_at->gt($issue->last_viewed_at));

        /** @var array<string, CarbonInterface|null> $triggers */
        $triggers = [
            self::Woke->value => $issue->snoozed_until?->isPast() ? $issue->snoozed_until : null,
            self::Mentioned->value => $issue->last_mentioned_at,
            self::Commented->value => $isUnreadComment ? $issue->last_commented_at : null,
            self::Assigned->value => $issue->assigned_to_me_at,
            ($issue->is_important ? self::Starred : self::Added)->value => $issue->concerning_since,
        ];

        $reason = null;
        $latest = null;

        foreach ($triggers as $value => $at) {
            if ($at !== null && ($latest === null || $at->gt($latest))) {
                $reason = self::from($value);
                $latest = $at;
            }
        }

        return $reason ?? match (true) {
            $issue->is_important => self::Starred,
            filled($user->jira_account_id) && $issue->assignee_account_id === $user->jira_account_id => self::Assigned,
            $issue->mentions_me => self::Mentioned,
            default => null,
        };
    }

    /**
     * The icon representing the reason.
     */
    public function icon(): Heroicon
    {
        return match ($this) {
            self::Woke => Heroicon::OutlinedBellAlert,
            self::Mentioned => Heroicon::OutlinedAtSymbol,
            self::Commented => Heroicon::OutlinedChatBubbleLeftEllipsis,
            self::Assigned => Heroicon::OutlinedUser,
            self::Starred => Heroicon::OutlinedStar,
            self::Added => Heroicon::OutlinedPlus,
        };
    }

    /**
     * The human-readable label (e.g. `Woke from snooze`).
     */
    public function label(): string
    {
        return match ($this) {
            self::Woke => 'Woke from snooze',
            self::Mentioned => 'Mentioned',
            self::Commented => 'New comment',
            self::Assigned => 'Assigned to you',
            self::Starred => 'Starred',
            self::Added => 'Added to list',
        };
    }
}
