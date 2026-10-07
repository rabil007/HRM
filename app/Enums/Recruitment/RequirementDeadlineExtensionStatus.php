<?php

namespace App\Enums\Recruitment;

enum RequirementDeadlineExtensionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for requester approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Cancelled], true);
    }
}
