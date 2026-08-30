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

    public function isTerminal(): bool
    {
        return match ($this) {
            self::AwaitingAction => false,
            self::Completed, self::Expired, self::Cancelled, self::Superseded => true,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
