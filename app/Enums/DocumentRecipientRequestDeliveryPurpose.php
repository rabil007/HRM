<?php

namespace App\Enums;

enum DocumentRecipientRequestDeliveryPurpose: string
{
    case Initial = 'initial';
    case ManualResend = 'manual_resend';
    case Reminder = 'reminder';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Initial',
            self::ManualResend => 'Manual resend',
            self::Reminder => 'Reminder',
        };
    }
}
