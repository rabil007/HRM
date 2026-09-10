<?php

namespace App\Enums;

enum AnnouncementWhatsAppPayloadProfile: string
{
    case LegacyV1 = 'announcement_legacy_v1';
    case TitleBodyV2 = 'announcement_title_body_v2';

    public function label(): string
    {
        return match ($this) {
            self::LegacyV1 => 'Legacy (5 body variables)',
            self::TitleBodyV2 => 'Title + Message',
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
