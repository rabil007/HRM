<?php

namespace App\Enums;

enum AnnouncementWhatsAppTemplatePurpose: string
{
    case General = 'general';
    case Promotion = 'promotion';
    case ActionRequired = 'action_required';
    case Reminder = 'reminder';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Promotion => 'Promotion',
            self::ActionRequired => 'Action Required',
            self::Reminder => 'Reminder',
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
