<?php

namespace App\Support\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;

final class AttendanceRecordVisibility
{
    public function canManageAll(?User $user): bool
    {
        return $user?->can('attendance.records.manage') ?? false;
    }

    public function linkedEmployeeId(?User $user, int $companyId): ?int
    {
        if ($user === null) {
            return null;
        }

        $employeeId = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->value('id');

        return $employeeId !== null ? (int) $employeeId : null;
    }

    /**
     * @param  Builder<AttendanceRecord>  $query
     */
    public function applyIndexScope($query, ?User $user, int $companyId): void
    {
        if ($this->canManageAll($user)) {
            // Managers may view all attendance records, but still restricted
            // to employees within their Role Employee Access Scope.
            EmployeeVisibilityScope::whereHas($query, $user, $companyId, 'employee');

            return;
        }

        $employeeId = $this->linkedEmployeeId($user, $companyId);

        if ($employeeId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('employee_id', $employeeId);
    }

    public function canAccess(AttendanceRecord $record, ?User $user, int $companyId): bool
    {
        if ((int) $record->company_id !== $companyId) {
            return false;
        }

        if ($this->canManageAll($user)) {
            // Still restricted to the manager's Role Employee Access Scope.
            $employee = $record->employee ?? Employee::query()->find($record->employee_id);

            return $employee !== null && EmployeeVisibilityScope::canAccess($user, $employee, $companyId);
        }

        $employeeId = $this->linkedEmployeeId($user, $companyId);

        return $employeeId !== null && (int) $record->employee_id === $employeeId;
    }

    public function assertCanAccess(AttendanceRecord $record, ?User $user, int $companyId): void
    {
        abort_unless($this->canAccess($record, $user, $companyId), 404);
    }

    /**
     * Create/update target employee. Managers may use any employee in the active
     * company. Self-service users may only use their linked active-company Employee.
     */
    public function canWriteForEmployee(?User $user, int $companyId, int $employeeId): bool
    {
        if ($employeeId < 1) {
            return false;
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->find($employeeId);

        if ($employee === null) {
            return false;
        }

        if ($this->canManageAll($user)) {
            // Managers may only write for employees within their Role Employee Access Scope.
            return EmployeeVisibilityScope::canAccess($user, $employee, $companyId);
        }

        $linkedId = $this->linkedEmployeeId($user, $companyId);

        return $linkedId !== null && $linkedId === $employeeId;
    }

    public function assertCanWriteForEmployee(?User $user, int $companyId, int $employeeId): void
    {
        abort_unless($this->canWriteForEmployee($user, $companyId, $employeeId), 404);
    }
}
