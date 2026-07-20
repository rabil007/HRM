<?php

namespace App\Enums;

enum CrewTimesheetSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case CrewOperations = 'crew_operations';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Import => 'Import',
            self::CrewOperations => 'Crew Operations',
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
