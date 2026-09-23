<?php

namespace App\Enums\Recruitment;

enum RequirementStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open',
            self::OnHold => 'On Hold',
            self::Completed => 'Filled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Open => 'success',
            self::OnHold => 'warning',
            self::Completed => 'default',
            self::Cancelled => 'destructive',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Open, self::OnHold], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Draft, self::Open], true);
    }
}
