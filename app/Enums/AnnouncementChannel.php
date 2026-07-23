<?php

namespace App\Enums;

enum AnnouncementChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case WhatsApp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'In-app',
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
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
