<?php

namespace App\Enums;

enum CrewTimesheetApprovalStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Approved => 'Approved',
            self::Returned => 'Returned',
        };
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function canSubmit(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    public function canApproveOrReturn(): bool
    {
        return $this === self::Submitted;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
