<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
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

        if ($user !== null && $assignment->employee !== null) {
            if (! EmployeeVisibilityScope::canAccess($user, $assignment->employee, $companyId)) {
                return null;
            }
        }

        return $assignment;
    }

    public static function assertInCompany(CrewAssignment $assignment, int $companyId, ?User $user = null): void
    {
        abort_unless($assignment->company_id === $companyId, 404);

        if ($user !== null) {
            $assignment->loadMissing('employee');
            if ($assignment->employee !== null) {
                abort_unless(EmployeeVisibilityScope::canAccess($user, $assignment->employee, $companyId), 404);
            }
        }
    }
}
