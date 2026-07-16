<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveRequestVisibility;
use App\Support\Attendance\LeaveTypeYearBalance;
use App\Support\Attendance\TodayAttendanceTimeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceCalendarController extends Controller
{
    public function __construct(
        private LeaveRequestVisibility $visibility,
        private LeaveTypeYearBalance $leaveTypeYearBalance,
        private TodayAttendanceTimeline $todayAttendanceTimeline,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        $year = $this->resolveYear($request);
        $linkedEmployeeId = $this->visibility->linkedEmployeeId($user, $companyId);
        $selectedEmployeeId = $this->visibility->resolveCalendarEmployeeId($request, $user, $companyId);
        $canSelectEmployee = $this->visibility->canViewAll($user);

        $leaveRequests = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->tap(fn (Builder $query) => $this->applyEmployeeScope($query, $selectedEmployeeId))
            ->where('start_date', '<=', "{$year}-12-31")
            ->where('end_date', '>=', "{$year}-01-01")
            ->with([
                'employee:id,company_id,employee_no,name',
                'leaveType:id,company_id,name,code,color',
            ])
            ->orderBy('start_date')
            ->get();

        $approvedLeaves = $leaveRequests
            ->map(fn (LeaveRequest $leaveRequest) => $this->serializeCalendarLeave($leaveRequest))
            ->values()
            ->all();

        $leaveTypes = $selectedEmployeeId !== null
            ? $this->leaveTypeYearBalance->forEmployee($companyId, $selectedEmployeeId, $year)
            : [];

        $pendingRequestCount = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->tap(fn (Builder $query) => $this->applyEmployeeScope($query, $selectedEmployeeId))
            ->where('start_date', '<=', "{$year}-12-31")
            ->where('end_date', '>=', "{$year}-01-01")
            ->count();

        $employees = $this->calendarEmployeeOptions($companyId, $selectedEmployeeId, $canSelectEmployee);
        $selectedEmployee = $this->selectedEmployeePayload($companyId, $selectedEmployeeId);
        $canApprove = $this->visibility->canViewAll($user);

        return Inertia::render('attendance/calendar', [
            'year' => $year,
            'today' => now()->toDateString(),
            'approved_leaves' => $approvedLeaves,
            'leave_types' => $leaveTypes,
            'pending_request_count' => $pendingRequestCount,
            'linked_employee_id' => $linkedEmployeeId,
            'selected_employee_id' => $selectedEmployeeId,
            'selected_employee' => $selectedEmployee,
            'employees' => $employees,
            'can_select_employee' => $canSelectEmployee,
            'form_employees' => $this->formEmployeeOptions($companyId, $user, $canApprove, $linkedEmployeeId),
            'form_leave_types' => LeaveType::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'color']),
            'can' => [
                'create' => $user?->can('attendance.leave-requests.create') ?? false,
                'approve' => $canApprove,
            ],
            'today_timeline' => $this->todayAttendanceTimeline->forEmployee($companyId, $selectedEmployeeId),
        ]);
    }

    /**
     * @return list<array{id: int, employee_no: string|null, name: string}>
     */
    private function formEmployeeOptions(int $companyId, ?User $user, bool $canApprove, ?int $linkedEmployeeId): array
    {
        if (! ($user?->can('attendance.leave-requests.create') ?? false)) {
            return [];
        }

        $employeesQuery = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name');

        if (! $canApprove) {
            $employeesQuery->when(
                $linkedEmployeeId !== null,
                fn ($query) => $query->whereKey($linkedEmployeeId),
                fn ($query) => $query->whereRaw('1 = 0'),
            );
        }

        return $employeesQuery
            ->get(['id', 'employee_no', 'name'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'name' => $employee->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, employee_no: string|null, name: string}>
     */
    private function calendarEmployeeOptions(int $companyId, ?int $selectedEmployeeId, bool $canSelectEmployee): array
    {
        if (! $canSelectEmployee) {
            return [];
        }

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereIn('id', LeaveRequest::query()
                ->where('company_id', $companyId)
                ->select('employee_id')
                ->distinct())
            ->orderBy('name')
            ->get(['id', 'employee_no', 'name']);

        if ($selectedEmployeeId !== null && ! $employees->contains('id', $selectedEmployeeId)) {
            $selected = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($selectedEmployeeId)
                ->first(['id', 'employee_no', 'name']);

            if ($selected !== null) {
                $employees->push($selected);
                $employees = $employees->sortBy('name')->values();
            }
        }

        return $employees
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'name' => $employee->name,
            ])
            ->all();
    }

    /**
     * @return array{id: int, employee_no: string|null, name: string}|null
     */
    private function selectedEmployeePayload(int $companyId, ?int $selectedEmployeeId): ?array
    {
        if ($selectedEmployeeId === null) {
            return null;
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($selectedEmployeeId)
            ->first(['id', 'employee_no', 'name']);

        if ($employee === null) {
            return null;
        }

        return [
            'id' => $employee->id,
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
        ];
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    private function applyEmployeeScope(Builder $query, ?int $selectedEmployeeId): void
    {
        if ($selectedEmployeeId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('employee_id', $selectedEmployeeId);
    }

    private function resolveYear(Request $request): int
    {
        $year = (int) $request->query('year', now()->year);

        return max(1970, min(2100, $year));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCalendarLeave(LeaveRequest $leaveRequest): array
    {
        return [
            'id' => $leaveRequest->id,
            'employee' => $leaveRequest->employee ? [
                'id' => $leaveRequest->employee->id,
                'name' => $leaveRequest->employee->name,
                'employee_no' => $leaveRequest->employee->employee_no,
            ] : null,
            'leave_type' => $leaveRequest->leaveType ? [
                'id' => $leaveRequest->leaveType->id,
                'name' => $leaveRequest->leaveType->name,
                'code' => $leaveRequest->leaveType->code,
                'color' => $leaveRequest->leaveType->color,
            ] : null,
            'start_date' => $leaveRequest->start_date?->toDateString(),
            'end_date' => $leaveRequest->end_date?->toDateString(),
        ];
    }
}
