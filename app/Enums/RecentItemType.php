<?php

namespace App\Enums;

use App\Models\User;

enum RecentItemType: string
{
    case Employee = 'employee';
    case Document = 'document';
    case CrewAssignment = 'crew_assignment';
    case Vessel = 'vessel';
    case PayrollPeriod = 'payroll_period';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Employee',
            self::Document => 'Document',
            self::CrewAssignment => 'Crew',
            self::Vessel => 'Vessel',
            self::PayrollPeriod => 'Payroll',
        };
    }

    public function resultPrefix(): string
    {
        return match ($this) {
            self::Employee => 'employee',
            self::Document => 'document',
            self::CrewAssignment => 'crew',
            self::Vessel => 'vessel',
            self::PayrollPeriod => 'payroll',
        };
    }

    public function resultId(int $recordId): string
    {
        return $this->resultPrefix().':'.$recordId;
    }

    public function isAccessible(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return match ($this) {
            self::Employee => $user->can('employees.view'),
            self::Document => $user->can('documents.view'),
            self::CrewAssignment => $user->can('crew_operations.assignments.view'),
            self::Vessel => $user->can('crew_operations.vessels.view'),
            self::PayrollPeriod => $user->can('payroll.periods.view')
                || $user->can('payroll.crew_timesheets.view'),
        };
    }
}
