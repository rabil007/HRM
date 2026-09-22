<?php

namespace App\Support\Reports;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceLeaveDepartmentScope;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class LeaveBalanceReportQuery
{
    public function __construct(
        private readonly int $companyId,
        private readonly LeaveBalanceReportFilters $filters,
        private readonly User $user,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return $this->ordered($this->filteredQuery())
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (LeaveBalance $balance): array => LeaveBalanceReportPresenter::toArray($balance));
    }

    /**
     * @return Builder<LeaveBalance>
     */
    public function exportQuery(): Builder
    {
        return $this->ordered($this->filteredQuery());
    }

    /**
     * @return array{employees: int, balance_rows: int, used_days: float, pending_days: float}
     */
    public function summary(): array
    {
        $query = $this->filteredQuery(withRelations: false);
        $counts = (clone $query)
            ->selectRaw('COUNT(*) as balance_rows')
            ->selectRaw('COUNT(DISTINCT leave_balances.employee_id) as employees')
            ->selectRaw('SUM(leave_balances.used_days) as used_days')
            ->selectRaw('SUM(leave_balances.pending_days) as pending_days')
            ->first();

        return [
            'employees' => (int) ($counts?->employees ?? 0),
            'balance_rows' => (int) ($counts?->balance_rows ?? 0),
            'used_days' => round((float) ($counts?->used_days ?? 0), 2),
            'pending_days' => round((float) ($counts?->pending_days ?? 0), 2),
        ];
    }

    /**
     * @return Builder<LeaveBalance>
     */
    private function filteredQuery(bool $withRelations = true): Builder
    {
        $query = LeaveBalance::query()->where('leave_balances.company_id', $this->companyId);

        EmployeeVisibilityScope::whereHas($query, $this->user, $this->companyId, 'employee');
        AttendanceLeaveDepartmentScope::whereHas($query, $this->companyId, 'employee');

        if ($withRelations) {
            $query->with([
                'employee:id,company_id,employee_no,name,department_id,status',
                'employee.department:id,name',
                'leaveType' => fn ($leaveType) => $leaveType->withTrashed()->select([
                    'id',
                    'company_id',
                    'name',
                    'code',
                    'category',
                ]),
            ]);
        }

        $query
            ->where('leave_balances.year', (int) $this->filters->year)
            ->when($this->filters->employeeId !== '', fn (Builder $inner) => $inner->where('leave_balances.employee_id', $this->filters->employeeId))
            ->when($this->filters->leaveTypeId !== '', fn (Builder $inner) => $inner->where('leave_balances.leave_type_id', $this->filters->leaveTypeId))
            ->when($this->filters->departmentId !== '', fn (Builder $inner) => $inner->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('department_id', $this->filters->departmentId),
            ))
            ->when($this->filters->employeeStatus !== '', fn (Builder $inner) => $inner->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('status', $this->filters->employeeStatus),
            ))
            ->when($this->filters->category !== '', fn (Builder $inner) => $inner->whereHas(
                'leaveType',
                fn (Builder $leaveType) => $leaveType->withTrashed()->where('category', $this->filters->category),
            ))
            ->when($this->filters->search !== '', function (Builder $inner): void {
                $like = '%'.$this->filters->search.'%';

                $inner->whereHas('employee', fn (Builder $employee) => $employee->where(
                    fn (Builder $match) => $match
                        ->where('name', 'like', $like)
                        ->orWhere('employee_no', 'like', $like),
                ));
            });

        return $query;
    }

    /**
     * @param  Builder<LeaveBalance>  $query
     * @return Builder<LeaveBalance>
     */
    private function ordered(Builder $query): Builder
    {
        return $query
            ->orderBy(
                Employee::query()->select('name')->whereColumn('employees.id', 'leave_balances.employee_id'),
            )
            ->orderBy(
                LeaveType::withTrashed()->select('name')->whereColumn('leave_types.id', 'leave_balances.leave_type_id'),
            )
            ->orderBy('leave_balances.id');
    }
}
