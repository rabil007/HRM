<?php

namespace App\Support\Employees\Actions;

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Prevents marking an active employee inactive/terminated while operational
 * work is still open. Historical records are never deleted.
 */
final class GuardEmployeeStatusTransition
{
    public static function assertCanLeaveActive(Employee $employee, string $newStatus): void
    {
        if ($employee->status !== 'active') {
            return;
        }

        if (! in_array($newStatus, ['inactive', 'terminated'], true)) {
            return;
        }

        $companyId = (int) $employee->company_id;

        if (self::hasOpenCrewAssignment($companyId, (int) $employee->id)) {
            throw ValidationException::withMessages([
                'status' => 'Employee cannot be marked '.$newStatus.' while they have an active crew assignment. Complete or void the crew assignment first.',
            ]);
        }

        if (self::hasCurrentOrFuturePlanning($companyId, (int) $employee->id)) {
            throw ValidationException::withMessages([
                'status' => 'Employee cannot be marked '.$newStatus.' while they have a current or future crew planning assignment. Remove or complete the planning bar first.',
            ]);
        }

        if (self::hasPendingLeave($companyId, (int) $employee->id)) {
            throw ValidationException::withMessages([
                'status' => 'Employee cannot be marked '.$newStatus.' while they have a pending leave request. Approve, reject, or cancel the leave request first.',
            ]);
        }

        if (self::isDepartmentManager($companyId, (int) $employee->id)) {
            throw ValidationException::withMessages([
                'status' => 'Employee cannot be marked '.$newStatus.' while they are assigned as a department manager. Reassign the department manager first.',
            ]);
        }
    }

    private static function hasOpenCrewAssignment(int $companyId, int $employeeId): bool
    {
        return CrewAssignment::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', [CrewAssignmentStatus::Draft, CrewAssignmentStatus::Active])
            ->exists();
    }

    private static function hasCurrentOrFuturePlanning(int $companyId, int $employeeId): bool
    {
        $today = CarbonImmutable::now(CompanyTimezone::forCompanyId($companyId))->toDateString();

        return CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where(function ($query) use ($today): void {
                $query->whereNull('planned_leave_date')
                    ->orWhereDate('planned_leave_date', '>=', $today);
            })
            ->exists();
    }

    private static function hasPendingLeave(int $companyId, int $employeeId): bool
    {
        return LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', 'pending')
            ->exists();
    }

    private static function isDepartmentManager(int $companyId, int $employeeId): bool
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->where('manager_id', $employeeId)
            ->exists();
    }
}
