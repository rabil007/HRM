<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\LeaveType;

/**
 * Shared gate for creating new leave balance rows (not for preserving existing balances).
 */
final class LeaveBalanceNewAllocationEligibility
{
    public function employeeCanReceiveNewBalance(Employee $employee, int $companyId): bool
    {
        if ((int) $employee->company_id !== $companyId) {
            return false;
        }

        if ($employee->trashed()) {
            return false;
        }

        if ((string) $employee->status !== 'active') {
            return false;
        }

        return AttendanceLeaveDepartmentScope::canAccessEmployee($employee, $companyId);
    }

    public function leaveTypeCanBeProvisioned(LeaveType $leaveType, int $companyId): bool
    {
        return (int) $leaveType->company_id === $companyId && (string) $leaveType->status === 'active';
    }
}
