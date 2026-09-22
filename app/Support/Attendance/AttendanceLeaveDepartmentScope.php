<?php

namespace App\Support\Attendance;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Restricts Attendance & Leave to employees whose CURRENT department
 * has include_in_attendance_leave = true. Direct department only — no parent inheritance.
 */
final class AttendanceLeaveDepartmentScope
{
    public const EXCLUDED_EMPLOYEE_MESSAGE = "The selected employee's department is not included in Attendance & Leave.";

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public static function apply(Builder $query, int $companyId): Builder
    {
        if ($companyId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('employees.company_id', $companyId);

        return $query->whereIn(
            'employees.department_id',
            self::includedDepartmentIdsSubquery($companyId),
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>|Relation<TModel, *, *>  $query
     * @return Builder<TModel>|Relation<TModel, *, *>
     */
    public static function whereHas(Builder|Relation $query, int $companyId, string $relation = 'employee'): Builder|Relation
    {
        if ($companyId <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas($relation, function (Builder $employee) use ($companyId): void {
            self::apply($employee, $companyId);
        });
    }

    public static function canAccessEmployee(Employee $employee, int $companyId): bool
    {
        if ($companyId <= 0 || (int) $employee->company_id !== $companyId) {
            return false;
        }

        if ($employee->department_id === null) {
            return false;
        }

        return Department::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $employee->department_id)
            ->where('include_in_attendance_leave', true)
            ->exists();
    }

    public static function canAccessEmployeeId(int $employeeId, int $companyId): bool
    {
        if ($companyId <= 0 || $employeeId <= 0) {
            return false;
        }

        $employee = Employee::withTrashed()
            ->whereKey($employeeId)
            ->where('company_id', $companyId)
            ->first(['id', 'company_id', 'department_id']);

        if ($employee === null) {
            return false;
        }

        return self::canAccessEmployee($employee, $companyId);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    public static function includedDepartmentIdsSubquery(int $companyId)
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->where('include_in_attendance_leave', true)
            ->select('id');
    }
}
