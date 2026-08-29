<?php

namespace App\Enums;

enum DocumentRecipientRequestDeliveryChannel: string
{
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
        };
    }
}
