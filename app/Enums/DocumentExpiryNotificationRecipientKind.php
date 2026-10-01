<?php

namespace App\Enums;

enum DocumentExpiryNotificationRecipientKind: string
{
    case User = 'user';
    case Email = 'email';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
