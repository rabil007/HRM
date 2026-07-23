<?php

namespace App\Enums;

enum CrewTimesheetBoardFilter: string
{
    case Ready = 'ready';
    case MissingTimesheet = 'missing_timesheet';
    case AwaitingApproval = 'awaiting_approval';
    case CrewOperations = 'crew_operations';
    case Manual = 'manual';
    case Import = 'import';
    case Returned = 'returned';

    public static function tryFromQuery(mixed $value): ?self
    {
        return self::tryFrom((string) $value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::MissingTimesheet => 'Missing Timesheet',
            self::AwaitingApproval => 'Awaiting Approval',
            self::CrewOperations => 'Crew Operations',
            self::Manual => 'Manual',
            self::Import => 'Import',
            self::Returned => 'Returned',
        };
    }
}
