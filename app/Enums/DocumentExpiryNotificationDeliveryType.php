<?php

namespace App\Enums;

enum DocumentExpiryNotificationDeliveryType: string
{
    case To = 'to';
    case Cc = 'cc';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
