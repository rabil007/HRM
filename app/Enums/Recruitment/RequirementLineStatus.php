<?php

namespace App\Enums\Recruitment;

enum RequirementLineStatus: string
{
    case Open = 'open';
    case OnHold = 'on_hold';
    case Filled = 'filled';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::OnHold => 'On Hold',
            self::Filled => 'Filled',
            self::Cancelled => 'Cancelled',
            self::Closed => 'Closed',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::OnHold => 'warning',
            self::Filled => 'default',
            self::Cancelled => 'destructive',
            self::Closed => 'secondary',
        };
    }
}
