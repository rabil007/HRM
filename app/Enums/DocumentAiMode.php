<?php

namespace App\Enums;

enum DocumentAiMode: string
{
    case Off = 'off';
    case Optional = 'optional';
    case Automatic = 'automatic';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Optional => 'Optional',
            self::Automatic => 'Automatic',
        };
    }
}
