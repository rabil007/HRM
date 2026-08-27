<?php

namespace App\Enums;

use App\Models\User;

enum SavedViewPage: string
{
    case Employees = 'employees';
    case Documents = 'documents';
    case Crew = 'crew';
    case Leave = 'leave';
    case Payroll = 'payroll';

    public function routeName(): string
    {
        return match ($this) {
            self::Employees => 'organization.employees',
            self::Documents => 'organization.documents.library',
            self::Crew => 'organization.crew-assignments.index',
            self::Leave => 'attendance.leave-requests.index',
            self::Payroll => 'payroll.index',
        };
    }

    public function userCanAccess(User $user): bool
    {
        return match ($this) {
            self::Employees => $user->can('employees.view'),
            self::Documents => $user->can('documents.view'),
            self::Crew => $user->can('crew_operations.assignments.view'),
            self::Leave => $user->can('attendance.leave-requests.view'),
            self::Payroll => $user->can('payroll.periods.view')
                || $user->can('payroll.crew_timesheets.view'),
        };
    }
}
