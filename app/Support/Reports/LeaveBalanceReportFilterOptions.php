<?php

namespace App\Support\Reports;

use App\Enums\LeaveTypeCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceLeaveDepartmentScope;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;

final class LeaveBalanceReportFilterOptions
{
    /**
     * @return list<int>
     */
    public static function years(User $user, int $companyId, int $businessYear): array
    {
        $years = self::visibleBalances($user, $companyId)
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->all();

        if (! in_array($businessYear, $years, true)) {
            $years[] = $businessYear;
        }

        rsort($years);

        return array_values(array_unique($years));
    }

    /**
     * @return list<array{id: int, name: string, employee_no: string|null, status: string|null}>
     */
    public static function employees(User $user, int $companyId): array
    {
        $query = Employee::query()
            ->where('employees.company_id', $companyId)
            ->whereExists(function ($subQuery) use ($companyId): void {
                $subQuery->selectRaw('1')
                    ->from('leave_balances')
                    ->whereColumn('leave_balances.employee_id', 'employees.id')
                    ->where('leave_balances.company_id', $companyId)
                    ->whereNull('leave_balances.deleted_at');
            })
            ->orderBy('employees.name');

        EmployeeVisibilityScope::apply($query, $user, $companyId);
        AttendanceLeaveDepartmentScope::apply($query, $companyId);

        return $query
            ->get(['employees.id', 'employees.employee_no', 'employees.name', 'employees.status'])
            ->map(fn (Employee $employee): array => [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no,
                'status' => $employee->status !== null ? (string) $employee->status : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function departments(User $user, int $companyId): array
    {
        $employeeIds = collect(self::employees($user, $companyId))->pluck('id')->all();

        if ($employeeIds === []) {
            return [];
        }

        return Department::query()
            ->where('company_id', $companyId)
            ->where('include_in_attendance_leave', true)
            ->whereIn('id', Employee::query()->whereIn('id', $employeeIds)->select('department_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => [
                'id' => (int) $department->id,
                'name' => (string) $department->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, code: string|null}>
     */
    public static function leaveTypes(User $user, int $companyId): array
    {
        $typeIds = self::visibleBalances($user, $companyId)
            ->distinct()
            ->pluck('leave_type_id')
            ->filter()
            ->all();

        if ($typeIds === []) {
            return [];
        }

        return LeaveType::withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('id', $typeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (LeaveType $leaveType): array => [
                'id' => (int) $leaveType->id,
                'name' => (string) $leaveType->name,
                'code' => $leaveType->code !== null ? (string) $leaveType->code : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function categories(): array
    {
        return array_map(
            fn (LeaveTypeCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
            ],
            LeaveTypeCategory::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function employeeStatuses(): array
    {
        return array_map(
            fn (string $status): array => [
                'value' => $status,
                'label' => LeaveBalanceReportFilters::employeeStatusLabel($status),
            ],
            LeaveBalanceReportFilters::employeeStatuses(),
        );
    }

    /**
     * @return Builder<LeaveBalance>
     */
    private static function visibleBalances(User $user, int $companyId): Builder
    {
        $query = LeaveBalance::query()->where('leave_balances.company_id', $companyId);

        EmployeeVisibilityScope::whereHas($query, $user, $companyId, 'employee');
        AttendanceLeaveDepartmentScope::whereHas($query, $companyId, 'employee');

        return $query;
    }
}
