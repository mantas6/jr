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
    case Merged = 'MERGED';
    case Declined = 'DECLINED';

    /**
     * Open requests take priority; declined requests do not affect the status.
     *
     * @param  list<string>  $statuses
     */
    public static function fromStatuses(array $statuses): ?self
    {
        $statuses = array_values(array_diff(array_map(mb_strtoupper(...), $statuses), [self::Declined->value]));

        if (in_array(self::Open->value, $statuses, true)) {
            return self::Open;
        }

        return $statuses !== [] && array_diff($statuses, [self::Merged->value]) === []
            ? self::Merged
            : null;
    }

    /**
     * The Filament color used for the state's icon.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'info',
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
            self::Open => Heroicon::OutlinedArrowPathRoundedSquare,
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
     * Describe a pull request summary, e.g. `2 pull requests, merged`.
     */
    public function describe(?int $count): ?string
    {
        if ($this === self::Declined) {
            return null;
        }

        $count = max(1, (int) $count);

        return $count.' '.($count === 1 ? 'pull request' : 'pull requests').', '.$this->label();
    }
}
