<?php

namespace App\Support\Reports;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class LeaveReportQuery
{
    private const SORTS = [
        'employee_name',
        'leave_type',
        'start_date',
        'end_date',
        'total_days',
        'status',
        'created_at',
        'decided_at',
    ];

    public function __construct(
        private readonly int $companyId,
        private readonly LeaveReportFilters $filters,
        private readonly string $timezone,
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
            ->through(fn (LeaveRequest $leaveRequest): array => LeaveReportPresenter::toArray(
                $leaveRequest,
                $this->timezone,
                $this->user,
            ));
    }

    /**
     * @return Builder<LeaveRequest>
     */
    public function exportQuery(): Builder
    {
        return $this->ordered($this->filteredQuery());
    }

    /**
     * @return array{total: int, approved: int, pending: int, approved_leave_days: float, employees_taking_leave: int}
     */
    public function summary(): array
    {
        $query = $this->filteredQuery(withRelations: false);
        $counts = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN total_days ELSE 0 END) as approved_leave_days")
            ->first();

        $employeesTakingLeave = (clone $query)
            ->where('status', 'approved')
            ->distinct('employee_id')
            ->count('employee_id');

        return [
            'total' => (int) ($counts?->total ?? 0),
            'approved' => (int) ($counts?->approved ?? 0),
            'pending' => (int) ($counts?->pending ?? 0),
            'approved_leave_days' => round((float) ($counts?->approved_leave_days ?? 0), 2),
            'employees_taking_leave' => $employeesTakingLeave,
        ];
    }

    /**
     * @return Builder<LeaveRequest>
     */
    private function filteredQuery(bool $withRelations = true): Builder
    {
        $query = LeaveRequest::query()
            ->where('leave_requests.company_id', $this->companyId);

        EmployeeVisibilityScope::whereHas($query, $this->user, $this->companyId, 'employee');

        if ($withRelations) {
            $query->with([
                'employee:id,company_id,employee_no,name,department_id,image',
                'employee.department:id,name',
                'leaveType:id,name,code,color',
                'approver:id,name',
            ]);
        }

        $query
            ->when($this->filters->search !== '', function (Builder $inner): void {
                $like = '%'.$this->filters->search.'%';

                $inner->whereHas('employee', fn (Builder $employee) => $employee
                    ->where('name', 'like', $like)
                    ->orWhere('employee_no', 'like', $like));
            })
            ->when($this->filters->employeeId !== '', fn (Builder $inner) => $inner->where('leave_requests.employee_id', $this->filters->employeeId))
            ->when($this->filters->leaveTypeId !== '', fn (Builder $inner) => $inner->where('leave_requests.leave_type_id', $this->filters->leaveTypeId))
            ->when($this->filters->status !== '', fn (Builder $inner) => $inner->where('leave_requests.status', $this->filters->status))
            ->when($this->filters->departmentId !== '', fn (Builder $inner) => $inner->whereHas(
                'employee',
                fn (Builder $employee) => $employee->where('department_id', $this->filters->departmentId),
            ))
            ->when($this->filters->submittedFrom !== '', fn (Builder $inner) => $inner->whereDate('leave_requests.created_at', '>=', $this->filters->submittedFrom))
            ->when($this->filters->submittedTo !== '', fn (Builder $inner) => $inner->whereDate('leave_requests.created_at', '<=', $this->filters->submittedTo))
            ->when($this->filters->decidedFrom !== '', fn (Builder $inner) => $inner->whereDate('leave_requests.decided_at', '>=', $this->filters->decidedFrom))
            ->when($this->filters->decidedTo !== '', fn (Builder $inner) => $inner->whereDate('leave_requests.decided_at', '<=', $this->filters->decidedTo));

        $this->applyLeavePeriodOverlap($query);

        return $query;
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    private function applyLeavePeriodOverlap(Builder $query): void
    {
        if ($this->filters->leaveFrom !== '') {
            $query->whereDate('leave_requests.end_date', '>=', $this->filters->leaveFrom);
        }

        if ($this->filters->leaveTo !== '') {
            $query->whereDate('leave_requests.start_date', '<=', $this->filters->leaveTo);
        }
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    private function ordered(Builder $query): Builder
    {
        $sort = in_array($this->filters->sort, self::SORTS, true) ? $this->filters->sort : 'start_date';
        $direction = $this->filters->direction;

        $ordered = match ($sort) {
            'employee_name' => $query->orderBy(
                Employee::query()->select('name')->whereColumn('employees.id', 'leave_requests.employee_id'),
                $direction,
            ),
            'leave_type' => $query->orderBy(
                LeaveType::query()->select('name')->whereColumn('leave_types.id', 'leave_requests.leave_type_id'),
                $direction,
            ),
            'start_date' => $query->orderBy('leave_requests.start_date', $direction),
            'end_date' => $query->orderBy('leave_requests.end_date', $direction),
            'total_days' => $query->orderBy('leave_requests.total_days', $direction),
            'status' => $query->orderBy('leave_requests.status', $direction),
            'created_at' => $query->orderBy('leave_requests.created_at', $direction),
            'decided_at' => $query->orderBy('leave_requests.decided_at', $direction),
            default => $query->orderBy('leave_requests.start_date', $direction),
        };

        return $ordered->orderByDesc('leave_requests.id');
    }
}
