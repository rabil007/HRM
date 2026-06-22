<?php

namespace App\Enums;

enum PayrollCategory: string
{
    case Office = 'office';
    case Crew = 'crew';

    public function label(): string
    {
        return match ($this) {
            self::Office => 'Office',
            self::Crew => 'Crew',
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
