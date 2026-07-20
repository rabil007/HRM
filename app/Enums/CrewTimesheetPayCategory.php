<?php

namespace App\Enums;

enum CrewTimesheetPayCategory: string
{
    case SignOnStandby = 'sign_on_standby';
    case Onsite = 'onsite';
    case SignOffStandby = 'sign_off_standby';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::SignOnStandby => 'Sign-on Standby',
            self::Onsite => 'Onsite',
            self::SignOffStandby => 'Sign-off Standby',
            self::Excluded => 'Excluded',
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
