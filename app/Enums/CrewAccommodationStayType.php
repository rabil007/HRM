<?php

namespace App\Enums;

enum CrewAccommodationStayType: string
{
    case PreJoin = 'pre_join';
    case PostSignoff = 'post_signoff';

    public function label(): string
    {
        return match ($this) {
            self::PreJoin => 'Pre-Joining Standby',
            self::PostSignoff => 'Demobilisation Standby',
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
