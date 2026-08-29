<?php

namespace App\Enums;

enum DocumentSigningFlowStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Blocked => 'Blocked',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Active || $this === self::Blocked;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
