<?php

declare(strict_types=1);

namespace App\Services\Jira;

use Filament\Support\Icons\Heroicon;

/**
 * The overall pull request state Jira reports in an issue's Development
 * summary, with the icon, color and label used to display it.
 */
enum JiraPullRequestState: string
{
    case Open = 'OPEN';
    case Draft = 'DRAFT';
    case Merged = 'MERGED';
    case Declined = 'DECLINED';

    /**
     * Open requests take priority over drafts, which take priority over merged
     * requests; declined requests do not affect the status.
     *
     * @param  list<string>  $statuses
     */
    public static function fromStatuses(array $statuses): ?self
    {
        $statuses = array_values(array_diff(array_map(mb_strtoupper(...), $statuses), [self::Declined->value]));

        if (in_array(self::Open->value, $statuses, true)) {
            return self::Open;
        }

        if (in_array(self::Draft->value, $statuses, true)) {
            return self::Draft;
        }

        return $statuses !== [] && array_diff($statuses, [self::Merged->value]) === []
            ? self::Merged
            : null;
    }

    /**
     * Whether any open or draft request has at least one reviewer approval.
     *
     * @param  list<array{status: string, approved: bool}>  $pullRequests
     */
    public static function hasApproval(array $pullRequests): bool
    {
        foreach ($pullRequests as $pullRequest) {
            if ($pullRequest['approved'] && self::tryFrom($pullRequest['status'])?->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the state represents a request still awaiting merge.
     */
    public function isActive(): bool
    {
        return $this === self::Open || $this === self::Draft;
    }

    /**
     * The Filament color used for the state's icon.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Draft => 'info',
            self::Merged => 'success',
            self::Declined => 'danger',
        };
    }

    /**
     * The icon representing the state.
     */
    public function icon(): ?Heroicon
    {
        return match ($this) {
            self::Open, self::Draft => Heroicon::OutlinedArrowPathRoundedSquare,
            self::Merged => Heroicon::OutlinedCheckCircle,
            self::Declined => null,
        };
    }

    /**
     * The lowercase human-readable label (e.g. `merged`).
     */
    public function label(): string
    {
        return mb_strtolower($this->name);
    }

    /**
     * Describe a pull request summary, e.g. `2 pull requests, merged` or
     * `1 pull request, open, approved`.
     */
    public function describe(?int $count, bool $approved = false): ?string
    {
        if ($this === self::Declined) {
            return null;
        }

        $count = max(1, (int) $count);

        return $count.' '.($count === 1 ? 'pull request' : 'pull requests').', '.$this->label()
            .($approved && $this->isActive() ? ', approved' : '');
    }
}
