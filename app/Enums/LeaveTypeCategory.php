<?php

namespace App\Enums;

enum LeaveTypeCategory: string
{
    case Annual = 'annual';
    case Sick = 'sick';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Annual => 'Annual',
            self::Sick => 'Sick',
            self::Other => 'Other',
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
