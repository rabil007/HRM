<?php

namespace App\Support\Attendance;

use App\Models\LeaveRequest;
use App\Support\Departments\ResolveDepartmentEffectiveManager;

/**
 * Resolves the employee's effective department manager for leave email placeholders.
 *
 * Distinct from pending/deciding approvers — never use HR/Specific Employee actors here.
 */
final class ResolveLeaveRequestEmailDepartmentManagerName
{
    public static function handle(LeaveRequest $leaveRequest): string
    {
        $employee = $leaveRequest->employee;

        if ($employee === null) {
            return '';
        }

        $manager = ResolveDepartmentEffectiveManager::managerForEmployee($employee);

        return filled($manager?->name) ? (string) $manager->name : '';
    }
}
