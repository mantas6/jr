<?php

declare(strict_types=1);

namespace App\Services\Jira;

/**
 * The display status of a Jira project version (release), with the badge
 * color used to show it.
 */
enum JiraReleaseStatus: string
{
    case Unreleased = 'Unreleased';
    case Overdue = 'Overdue';
    case Released = 'Released';
    case Archived = 'Archived';

    /**
     * Archived wins over released, which wins over overdue.
     */
    public static function forFlags(bool $released, bool $archived, bool $overdue): self
    {
        return match (true) {
            $archived => self::Archived,
            $released => self::Released,
            $overdue => self::Overdue,
            default => self::Unreleased,
        };
    }

    /**
     * The Filament badge color for the status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Unreleased => 'info',
            self::Overdue => 'danger',
            self::Released => 'success',
            self::Archived => 'gray',
        };
    }
}
