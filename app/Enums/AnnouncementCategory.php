<?php

namespace App\Enums;

enum AnnouncementCategory: string
{
    case General = 'general';
    case Hr = 'hr';
    case Operations = 'operations';
    case Safety = 'safety';
    case Policy = 'policy';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Hr => 'HR',
            self::Operations => 'Operations',
            self::Safety => 'Safety',
            self::Policy => 'Policy',
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
