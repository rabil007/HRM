<?php

namespace App\Enums;

enum CrewTimesheetBoardFilter: string
{
    case Ready = 'ready';
    case MissingTimesheet = 'missing_timesheet';
    case CrewOperations = 'crew_operations';
    case Manual = 'manual';
    case Import = 'import';

    /**
     * Retired approval-workflow filters (ignored when submitted).
     *
     * @var list<string>
     */
    private const RETIRED_QUERY_VALUES = [
        'awaiting_approval',
        'returned',
    ];

    public static function tryFromQuery(mixed $value): ?self
    {
        $normalized = (string) $value;

        if ($normalized === '' || in_array($normalized, self::RETIRED_QUERY_VALUES, true)) {
            return null;
        }

        return self::tryFrom($normalized);
    }

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::MissingTimesheet => 'Missing Timesheet',
            self::CrewOperations => 'Crew Assignments',
            self::Manual => 'Manual',
            self::Import => 'Excel Import',
        };
    }
}
