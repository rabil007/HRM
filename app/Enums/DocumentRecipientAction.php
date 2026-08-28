<?php

namespace App\Enums;

enum DocumentRecipientAction: string
{
    case Sign = 'sign';
    case Acknowledge = 'acknowledge';

    public function label(): string
    {
        return match ($this) {
            self::Sign => 'Sign',
            self::Acknowledge => 'Acknowledge',
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
