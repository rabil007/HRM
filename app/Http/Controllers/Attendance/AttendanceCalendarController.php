<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Support\Attendance\LeaveRequestVisibility;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceCalendarController extends Controller
{
    public function __construct(
        private LeaveRequestVisibility $visibility,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        $year = $this->resolveYear($request);

        $leaveRequests = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->tap(fn ($query) => $this->visibility->applyIndexScope($query, $user, $companyId))
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

        $leaveTypes = $leaveRequests
            ->pluck('leaveType')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->map(fn ($leaveType) => [
                'id' => $leaveType->id,
                'name' => $leaveType->name,
                'code' => $leaveType->code,
                'color' => $leaveType->color,
            ])
            ->all();

        $pendingRequestCount = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->tap(fn ($query) => $this->visibility->applyIndexScope($query, $user, $companyId))
            ->where('start_date', '<=', "{$year}-12-31")
            ->where('end_date', '>=', "{$year}-01-01")
            ->count();

        return Inertia::render('attendance/calendar', [
            'year' => $year,
            'today' => now()->toDateString(),
            'approved_leaves' => $approvedLeaves,
            'leave_types' => $leaveTypes,
            'pending_request_count' => $pendingRequestCount,
        ]);
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
