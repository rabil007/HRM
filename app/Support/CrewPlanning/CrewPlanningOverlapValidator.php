<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeDeployment;

final class CrewPlanningOverlapValidator
{
    /**
     * Returns true when the given employee already has an overlapping draft assignment
     * or an overlapping confirmed deployment in the same company.
     *
     * Pass `$excludeAssignmentId` on update to avoid matching the record being updated.
     */
    public static function employeeIsDoubleBooked(
        int $companyId,
        ?int $employeeId,
        string $joinDate,
        string $leaveDate,
        ?int $excludeAssignmentId = null,
    ): bool {
        if ($employeeId === null) {
            return false;
        }

        $assignmentOverlap = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', 'draft')
            ->whereDate('planned_join_date', '<=', $leaveDate)
            ->whereDate('planned_leave_date', '>=', $joinDate)
            ->when($excludeAssignmentId !== null, fn ($q) => $q->whereKeyNot($excludeAssignmentId))
            ->exists();

        if ($assignmentOverlap) {
            return true;
        }

        return EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereNotNull('joined_date')
            ->whereDate('joined_date', '<=', $leaveDate)
            ->where(fn ($q) => $q
                ->whereNull('disembarked_date')
                ->orWhereDate('disembarked_date', '>=', $joinDate)
            )
            ->exists();
    }
}
