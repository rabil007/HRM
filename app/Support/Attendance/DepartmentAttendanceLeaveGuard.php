<?php

namespace App\Support\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Support\Dashboard\DashboardAnalytics;

/**
 * Pending-leave safeguards for Attendance & Leave department participation.
 *
 * Disabling, deleting, or moving employees out of an included department while
 * pending LeaveRequests exist would strand the Approvals action queue.
 */
final class DepartmentAttendanceLeaveGuard
{
    public static function pendingLeaveCountForDepartment(int $companyId, int $departmentId): int
    {
        if ($companyId <= 0 || $departmentId <= 0) {
            return 0;
        }

        return LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->whereIn(
                'employee_id',
                Employee::query()
                    ->where('company_id', $companyId)
                    ->where('department_id', $departmentId)
                    ->select('id'),
            )
            ->count();
    }

    public static function pendingLeaveCountForEmployee(int $companyId, int $employeeId): int
    {
        if ($companyId <= 0 || $employeeId <= 0) {
            return 0;
        }

        return LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', 'pending')
            ->count();
    }

    public static function departmentExcludesAttendanceLeave(int $companyId, ?int $departmentId): bool
    {
        if ($departmentId === null || $departmentId <= 0) {
            return true;
        }

        $department = Department::query()
            ->where('company_id', $companyId)
            ->whereKey($departmentId)
            ->first(['id', 'include_in_attendance_leave']);

        if ($department === null) {
            return true;
        }

        return ! (bool) $department->include_in_attendance_leave;
    }

    public static function exclusionBlockedMessage(int $pendingCount): string
    {
        $label = $pendingCount === 1 ? '1 pending leave request' : "{$pendingCount} pending leave requests";

        return "This department has {$label}. Approve, reject, cancel, or administratively resolve them before excluding the department from Attendance & Leave.";
    }

    public static function deletionBlockedMessage(int $pendingCount): string
    {
        $label = $pendingCount === 1 ? '1 pending leave request' : "{$pendingCount} pending leave requests";

        return "This department has {$label}. Approve, reject, cancel, or administratively resolve them before deleting the department.";
    }

    public static function employeeMoveBlockedMessage(): string
    {
        return 'This employee has pending leave requests. Resolve them before moving the employee to a department excluded from Attendance & Leave.';
    }

    /**
     * True when true → false exclusion should be rejected due to pending leave.
     */
    public static function cannotExcludeDepartment(int $companyId, Department $department): ?string
    {
        if (! (bool) $department->include_in_attendance_leave) {
            return null;
        }

        $pendingCount = self::pendingLeaveCountForDepartment($companyId, (int) $department->id);

        return $pendingCount > 0 ? self::exclusionBlockedMessage($pendingCount) : null;
    }

    /**
     * True when soft-delete should be rejected due to pending leave.
     */
    public static function cannotDeleteDepartment(int $companyId, Department $department): ?string
    {
        $pendingCount = self::pendingLeaveCountForDepartment($companyId, (int) $department->id);

        return $pendingCount > 0 ? self::deletionBlockedMessage($pendingCount) : null;
    }

    /**
     * Block moving an employee from an included department to null/excluded while pending leave exists.
     *
     * When $lockedDestination is provided (same company + destination id), its
     * include_in_attendance_leave value is authoritative for the destination check.
     *
     * @param  int|null|string  $destinationDepartmentId
     */
    public static function cannotMoveEmployeeToDepartment(
        Employee $employee,
        int $companyId,
        mixed $destinationDepartmentId,
        ?Department $lockedDestination = null,
    ): ?string {
        if ((int) $employee->company_id !== $companyId) {
            return null;
        }

        $currentDepartmentId = $employee->department_id !== null ? (int) $employee->department_id : null;
        $nextDepartmentId = ($destinationDepartmentId === null || $destinationDepartmentId === '')
            ? null
            : (int) $destinationDepartmentId;

        if ($currentDepartmentId === $nextDepartmentId) {
            return null;
        }

        if (self::departmentExcludesAttendanceLeave($companyId, $currentDepartmentId)) {
            return null;
        }

        $destinationExcludes = self::destinationExcludesAttendanceLeave(
            $companyId,
            $nextDepartmentId,
            $lockedDestination,
        );

        if (! $destinationExcludes) {
            return null;
        }

        $pendingCount = self::pendingLeaveCountForEmployee($companyId, (int) $employee->id);

        return $pendingCount > 0 ? self::employeeMoveBlockedMessage() : null;
    }

    private static function destinationExcludesAttendanceLeave(
        int $companyId,
        ?int $destinationDepartmentId,
        ?Department $lockedDestination,
    ): bool {
        if ($destinationDepartmentId === null || $destinationDepartmentId <= 0) {
            return true;
        }

        if (
            $lockedDestination !== null
            && (int) $lockedDestination->company_id === $companyId
            && (int) $lockedDestination->id === $destinationDepartmentId
        ) {
            return ! (bool) $lockedDestination->include_in_attendance_leave;
        }

        return self::departmentExcludesAttendanceLeave($companyId, $destinationDepartmentId);
    }

    public static function forgetDashboardCache(int $companyId): void
    {
        if ($companyId > 0) {
            DashboardAnalytics::forgetCompany($companyId);
        }
    }
}
