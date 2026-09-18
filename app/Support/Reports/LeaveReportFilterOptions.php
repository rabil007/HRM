<?php

namespace App\Support\Reports;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;

final class LeaveReportFilterOptions
{
    /**
     * @return list<array{id: int, name: string, employee_no: string|null}>
     */
    public static function employees(User $user, int $companyId): array
    {
        $query = Employee::query()
            ->where('employees.company_id', $companyId)
            ->whereExists(function ($subQuery) use ($companyId): void {
                $subQuery->selectRaw('1')
                    ->from('leave_requests')
                    ->whereColumn('leave_requests.employee_id', 'employees.id')
                    ->where('leave_requests.company_id', $companyId);
            })
            ->orderBy('employees.name');

        EmployeeVisibilityScope::apply($query, $user, $companyId);

        return $query
            ->get(['employees.id', 'employees.employee_no', 'employees.name'])
            ->map(fn (Employee $employee) => [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, code: string, color: string|null}>
     */
    public static function leaveTypes(User $user, int $companyId): array
    {
        $visibleLeaveTypeIds = self::visibleLeaveTypeIds($user, $companyId);

        if ($visibleLeaveTypeIds === []) {
            return [];
        }

        return LeaveType::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $visibleLeaveTypeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'color'])
            ->map(fn (LeaveType $type) => [
                'id' => (int) $type->id,
                'name' => (string) $type->name,
                'code' => (string) $type->code,
                'color' => $type->color,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function departments(User $user, int $companyId): array
    {
        $departmentIds = Employee::query()
            ->where('employees.company_id', $companyId)
            ->whereNotNull('employees.department_id')
            ->whereExists(function ($subQuery) use ($companyId): void {
                $subQuery->selectRaw('1')
                    ->from('leave_requests')
                    ->whereColumn('leave_requests.employee_id', 'employees.id')
                    ->where('leave_requests.company_id', $companyId);
            })
            ->tap(fn (Builder $query) => EmployeeVisibilityScope::apply($query, $user, $companyId))
            ->distinct()
            ->pluck('employees.department_id')
            ->filter()
            ->values()
            ->all();

        if ($departmentIds === []) {
            return [];
        }

        return Department::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $departmentIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department) => [
                'id' => (int) $department->id,
                'name' => (string) $department->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private static function visibleLeaveTypeIds(User $user, int $companyId): array
    {
        $query = LeaveRequest::query()
            ->where('leave_requests.company_id', $companyId);

        EmployeeVisibilityScope::whereHas($query, $user, $companyId, 'employee');

        return $query
            ->distinct()
            ->pluck('leave_requests.leave_type_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
