<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeDeployment;

final class CrewPlanningOverlapValidator
{
    /**
     * Returns true when the given employee already has an overlapping draft assignment
     * or an overlapping latest deployment in the same company.
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
            ->when($excludeAssignmentId !== null, fn ($q) => $q->whereKeyNot($excludeAssignmentId))
            ->get(['planned_join_date', 'planned_leave_date'])
            ->contains(fn (CrewPlanningAssignment $assignment): bool => self::rangesOverlap(
                $joinDate,
                $leaveDate,
                $assignment->planned_join_date->toDateString(),
                $assignment->planned_leave_date->toDateString(),
            ));

        if ($assignmentOverlap) {
            return true;
        }

        $latestDeployment = EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereNotNull('joined_date')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->first(['joined_date', 'disembarked_date']);

        if ($latestDeployment === null) {
            return false;
        }

        return self::rangesOverlap(
            $joinDate,
            $leaveDate,
            $latestDeployment->joined_date->toDateString(),
            $latestDeployment->disembarked_date?->toDateString(),
        );
    }

    /**
     * Inclusive date-range overlap. A null end date means the range is open-ended.
     */
    public static function rangesOverlap(
        string $startA,
        string $endA,
        string $startB,
        ?string $endB,
    ): bool {
        if ($endB === null) {
            return $startB <= $endA;
        }

        return $startA <= $endB && $endA >= $startB;
    }
}
