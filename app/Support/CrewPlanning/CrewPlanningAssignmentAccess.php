<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

final class CrewPlanningAssignmentAccess
{
    public static function canAccess(
        CrewPlanningAssignment $assignment,
        int $companyId,
        ?User $user = null,
    ): bool {
        if ((int) $assignment->company_id !== $companyId) {
            return false;
        }

        if ($user === null) {
            return true;
        }

        $assignment->loadMissing(['employee', 'relievedAssignment.employee']);

        if ($assignment->employee !== null && ! EmployeeVisibilityScope::canAccess($user, $assignment->employee, $companyId)) {
            return false;
        }

        $relievedEmployee = $assignment->relievedAssignment?->employee;

        if ($relievedEmployee !== null && ! EmployeeVisibilityScope::canAccess($user, $relievedEmployee, $companyId)) {
            return false;
        }

        return true;
    }

    public static function assertInCompany(
        CrewPlanningAssignment $assignment,
        int $companyId,
        ?User $user = null,
    ): void {
        abort_unless(self::canAccess($assignment, $companyId, $user), 404);
    }
}
