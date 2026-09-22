<?php

namespace App\Support\Reports;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceLeaveDepartmentScope;
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
     * @return array{
     *     total_leave_days: float,
     *     approved_leave_days: float,
     *     pending_leave_days: float,
     *     annual: array{approved: float, pending: float, total: float},
     *     sick: array{approved: float, pending: float, total: float}
     * }
     */
    public function summary(): array
    {
        return app(LeaveReportDaySummary::class)->summarize(
            $this->filteredQuery(withRelations: false),
            $this->filters->leaveFrom,
            $this->filters->leaveTo,
        );
    }

    /**
     * @return Builder<LeaveRequest>
     */
    private function filteredQuery(bool $withRelations = true): Builder
    {
        $query = LeaveRequest::query()
            ->where('leave_requests.company_id', $this->companyId);

        EmployeeVisibilityScope::whereHas($query, $this->user, $this->companyId, 'employee');
        AttendanceLeaveDepartmentScope::whereHas($query, $this->companyId, 'employee');

        if ($withRelations) {
            $query->with([
                'employee:id,company_id,employee_no,name,department_id,image',
                'employee.department:id,name',
                'leaveType' => fn ($leaveType) => $leaveType->withTrashed()->select([
                    'id',
                    'company_id',
                    'name',
                    'code',
                    'color',
                    'category',
                ]),
                'approver:id,name',
                'approvals' => fn ($approvals) => $approvals
                    ->where('company_id', $this->companyId)
                    ->where('is_required', true)
                    ->orderBy('sequence')
                    ->with([
                        'approverEmployee' => fn ($employee) => $employee
                            ->withTrashed()
                            ->select(['id', 'company_id', 'name']),
                        'approverUser:id,name',
                    ]),
                'approvalReassignments' => fn ($reassignments) => $reassignments
                    ->where('company_id', $this->companyId)
                    ->orderBy('id'),
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
                LeaveType::withTrashed()->select('name')->whereColumn('leave_types.id', 'leave_requests.leave_type_id'),
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
