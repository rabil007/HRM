<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

final class CrewPlanningAssignmentAccess
{
    public static function assertInCompany(
        CrewPlanningAssignment $assignment,
        int $companyId,
        ?User $user = null,
    ): void {
        abort_unless((int) $assignment->company_id === $companyId, 404);

        if ($user === null) {
            return;
        }

        $assignment->loadMissing(['employee', 'relievedAssignment.employee']);

        if ($assignment->employee !== null) {
            abort_unless(
                EmployeeVisibilityScope::canAccess($user, $assignment->employee, $companyId),
                404,
            );
        }

        $relievedEmployee = $assignment->relievedAssignment?->employee;

        if ($relievedEmployee !== null) {
            abort_unless(
                EmployeeVisibilityScope::canAccess($user, $relievedEmployee, $companyId),
                404,
            );
        }
    }
}
