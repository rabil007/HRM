<?php

namespace App\Enums;

enum CrewTimesheetMode: string
{
    case Manual = 'manual';
    case CrewOperations = 'crew_operations';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual / Excel Timesheet',
            self::CrewOperations => 'Crew Operations Timeline',
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
