<?php

namespace App\Enums\Recruitment;

enum RequirementLineStatus: string
{
    case Open = 'open';
    case OnHold = 'on_hold';
    case Filled = 'filled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::OnHold => 'On Hold',
            self::Filled => 'Filled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::OnHold => 'warning',
            self::Filled => 'default',
            self::Cancelled => 'destructive',
        };
    }
}
