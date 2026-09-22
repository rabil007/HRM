<?php

namespace App\Support\Employees;

use App\Models\User;
use App\Support\Attendance\AttendanceLeaveDepartmentScope;
use Illuminate\Validation\Rules\Exists;

/**
 * Active company employee that also belongs to an Attendance & Leave–included department.
 */
final class AttendanceLeaveEligibleEmployeeRule
{
    public static function exists(int $companyId, ?User $user = null): Exists
    {
        return ActiveCompanyEmployeeRule::exists($companyId, $user)->where(function ($query) use ($companyId): void {
            $query->whereIn(
                'department_id',
                AttendanceLeaveDepartmentScope::includedDepartmentIdsSubquery($companyId),
            );
        });
    }
}
