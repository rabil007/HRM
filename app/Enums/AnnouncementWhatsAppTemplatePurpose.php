<?php

namespace App\Enums;

enum AnnouncementWhatsAppTemplatePurpose: string
{
    case Promotion = 'promotion';
    case Internal = 'internal';
    case Safety = 'safety';
    case Crew = 'crew';
    case Training = 'training';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Promotion => 'Promotion',
            self::Internal => 'Internal',
            self::Safety => 'Safety',
            self::Crew => 'Crew',
            self::Training => 'Training',
            self::General => 'General',
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
