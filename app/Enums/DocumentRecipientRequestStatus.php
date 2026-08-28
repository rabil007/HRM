<?php

namespace App\Enums;

enum DocumentRecipientRequestStatus: string
{
    case AwaitingAction = 'awaiting_action';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingAction => 'Awaiting action',
            self::Completed => 'Completed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Superseded => 'Superseded',
        };
    }

    public function isActive(): bool
    {
        return $this === self::AwaitingAction;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
