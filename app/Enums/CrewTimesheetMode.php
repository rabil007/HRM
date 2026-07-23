<?php

namespace App\Enums;

enum CrewTimesheetMode: string
{
    case Manual = 'manual';
    case CrewOperations = 'crew_operations';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual / Excel Timesheet',
            self::CrewOperations => 'Crew Operations Timeline',
            self::Hybrid => 'Crew Payroll',
        };
    }

    public function supportsCrewOperationsTimeline(): bool
    {
        return $this === self::CrewOperations || $this === self::Hybrid;
    }

    public function requiresExclusiveCrewOperations(): bool
    {
        return $this === self::CrewOperations;
    }

    public function allowsFallbackOperationalEntry(): bool
    {
        return $this === self::Manual || $this === self::Hybrid;
    }

    public function usesMixedTimesheetSources(): bool
    {
        return $this === self::Hybrid;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
