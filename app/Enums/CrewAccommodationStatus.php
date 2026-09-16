<?php

namespace App\Enums;

enum CrewAccommodationStatus: string
{
    case Hotel = 'hotel';
    case NoAccommodation = 'no_accommodation';

    public function label(): string
    {
        return match ($this) {
            self::Hotel => 'Hotel',
            self::NoAccommodation => 'No Accommodation',
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
