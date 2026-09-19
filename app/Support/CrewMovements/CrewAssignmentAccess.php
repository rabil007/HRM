<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

class CrewAssignmentAccess
{
    public static function findForCompany(int $companyId, int $id, ?User $user = null): ?CrewAssignment
    {
        $assignment = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereKey($id)
            ->with([
                'employee',
                'rank',
                'client',
                'vessel',
                'currentPhase',
                'phases',
                'planningAssignment',
            ])
            ->first();

        if ($assignment === null) {
            return null;
        }

        if ($user !== null) {
            $employee = $assignment->relationLoaded('employee') && $assignment->employee !== null
                ? $assignment->employee
                : Employee::withTrashed()
                    ->whereKey($assignment->employee_id)
                    ->where('company_id', $companyId)
                    ->first();

            if ($employee === null || ! EmployeeVisibilityScope::canAccess($user, $employee, $companyId)) {
                return null;
            }
        }

        return $assignment;
    }

    public static function assertInCompany(CrewAssignment $assignment, int $companyId, ?User $user = null): void
    {
        abort_unless((int) $assignment->company_id === $companyId, 404);

        if ($user !== null) {
            $employee = $assignment->relationLoaded('employee') && $assignment->employee !== null
                ? $assignment->employee
                : Employee::withTrashed()
                    ->whereKey($assignment->employee_id)
                    ->where('company_id', $companyId)
                    ->first();

            if ($employee === null) {
                abort(404);
            }

            abort_unless(EmployeeVisibilityScope::canAccess($user, $employee, $companyId), 404);
        }
    }
}
