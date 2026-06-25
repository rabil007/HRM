<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ApproveLeaveRequestRequest;
use App\Http\Requests\Attendance\CancelLeaveRequestRequest;
use App\Http\Requests\Attendance\RejectLeaveRequestRequest;
use App\Http\Requests\Attendance\StoreLeaveRequestRequest;
use App\Http\Requests\Attendance\UpdateLeaveRequestRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Attendance\Actions\SendLeaveRequestDecidedEmail;
use App\Support\Attendance\Actions\SendLeaveRequestSubmittedEmail;
use App\Support\Attendance\CalculateLeaveRequestDays;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAttachments;
use App\Support\Attendance\LeaveRequestVisibility;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaveRequestController extends Controller
{
    use ResolvesPerPage;

    public function __construct(
        private LeaveRequestAttachments $attachments,
        private LeaveRequestVisibility $visibility,
        private LeaveBalanceManager $leaveBalances,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $employeeId = trim((string) $request->query('employee_id', ''));
        $leaveTypeId = trim((string) $request->query('leave_type_id', ''));

        $user = $request->user();

        $paginator = LeaveRequest::query()
            ->with([
                'employee:id,company_id,employee_no,name',
                'leaveType:id,company_id,name,code,color',
                'approver:id,name',
            ])
            ->where('company_id', $companyId)
            ->tap(fn ($query) => $this->visibility->applyIndexScope($query, $user, $companyId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->when($leaveTypeId, fn ($query) => $query->where('leave_type_id', $leaveTypeId))
            ->when($search, function ($query) use ($search) {
                $query->whereHas('employee', function ($employeeQuery) use ($search) {
                    $employeeQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $leaveRequests = $paginator->through(fn (LeaveRequest $leaveRequest) => $this->serializeLeaveRequest($leaveRequest));

        $canViewAll = $this->visibility->canViewAll($user);
        $linkedEmployeeId = $this->visibility->linkedEmployeeId($user, $companyId);

        $employeesQuery = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name');

        if (! $canViewAll) {
            $employeesQuery->when(
                $linkedEmployeeId !== null,
                fn ($query) => $query->whereKey($linkedEmployeeId),
                fn ($query) => $query->whereRaw('1 = 0'),
            );
        }

        $countsQuery = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->tap(fn ($query) => $this->visibility->applyIndexScope($query, $user, $companyId))
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->when($leaveTypeId, fn ($query) => $query->where('leave_type_id', $leaveTypeId))
            ->when($search, function ($query) use ($search) {
                $query->whereHas('employee', function ($employeeQuery) use ($search) {
                    $employeeQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                });
            });

        $statusCounts = $countsQuery->clone()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        $totalCount = array_sum($statusCounts);

        return Inertia::render('attendance/leave-requests', [
            'leave_requests' => $leaveRequests->items(),
            'pagination' => $this->paginationMeta($paginator),
            'status_counts' => [
                'all' => $totalCount,
                'pending' => $statusCounts['pending'] ?? 0,
                'approved' => $statusCounts['approved'] ?? 0,
                'rejected' => $statusCounts['rejected'] ?? 0,
                'cancelled' => $statusCounts['cancelled'] ?? 0,
            ],
            'search' => $search,
            'filters' => [
                'status' => $status,
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
            ],
            'employees' => $employeesQuery->get(['id', 'employee_no', 'name']),
            'linked_employee_id' => $linkedEmployeeId,
            'leave_types' => LeaveType::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'color']),
            'can' => [
                'create' => $user?->can('attendance.leave-requests.create') ?? false,
                'update' => $user?->can('attendance.leave-requests.update') ?? false,
                'delete' => $user?->can('attendance.leave-requests.delete') ?? false,
                'approve' => $user?->can('attendance.leave-requests.approve') ?? false,
            ],
        ]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();

        $this->visibility->assertCanAccess($leaveRequest, $user, $companyId);

        $leaveRequest->load([
            'employee:id,company_id,employee_no,name',
            'leaveType:id,company_id,name,code,color',
            'approver:id,name',
        ]);

        $canViewAll = $this->visibility->canViewAll($user);
        $linkedEmployeeId = $this->visibility->linkedEmployeeId($user, $companyId);

        $employeesQuery = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name');

        if (! $canViewAll) {
            $employeesQuery->when(
                $linkedEmployeeId !== null,
                fn ($query) => $query->whereKey($linkedEmployeeId),
                fn ($query) => $query->whereRaw('1 = 0'),
            );
        }

        return Inertia::render('attendance/leave-request', [
            'leave_request' => $this->serializeLeaveRequest($leaveRequest),
            'employees' => $employeesQuery->get(['id', 'employee_no', 'name']),
            'leave_types' => LeaveType::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'color']),
            'linked_employee_id' => $linkedEmployeeId,
            'recent_activity' => RecentActivityQuery::for(
                $user,
                $companyId,
                LeaveRequest::class,
                $leaveRequest->id,
            ),
            'can_view_audit' => $user?->can('audit.view') ?? false,
            'can' => [
                'create' => $user?->can('attendance.leave-requests.create') ?? false,
                'update' => $user?->can('attendance.leave-requests.update') ?? false,
                'delete' => $user?->can('attendance.leave-requests.delete') ?? false,
                'approve' => $user?->can('attendance.leave-requests.approve') ?? false,
            ],
        ]);
    }

    public function store(StoreLeaveRequestRequest $request, CalculateLeaveRequestDays $calculateDays): RedirectResponse
    {
        $data = $request->validated();
        $companyId = (int) $request->attributes->get('current_company_id');

        $leaveRequest = LeaveRequest::query()->create([
            'company_id' => $companyId,
            'employee_id' => $data['employee_id'],
            'leave_type_id' => $data['leave_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'total_days' => $calculateDays($data['start_date'], $data['end_date']),
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
        ]);

        if ($request->hasFile('attachment')) {
            $leaveRequest->update([
                'attachments' => $this->attachments->store(
                    $request->file('attachment'),
                    $companyId,
                    $leaveRequest->id,
                ),
            ]);
        }

        $this->leaveBalances->reserveLeaveRequest($leaveRequest->fresh());

        try {
            app(SendLeaveRequestSubmittedEmail::class)->handle($leaveRequest->fresh());
        } catch (\Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('attendance.leave-requests.index')
            ->with('success', 'Leave request created successfully.');
    }

    public function update(
        UpdateLeaveRequestRequest $request,
        LeaveRequest $leaveRequest,
        CalculateLeaveRequestDays $calculateDays,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($leaveRequest, $request->user(), $companyId);

        if ($leaveRequest->status !== 'pending') {
            return redirect()
                ->route('attendance.leave-requests.index')
                ->withErrors(['leave_request' => 'Only pending leave requests can be updated.']);
        }

        $data = $request->validated();

        $attachmentPayload = $this->resolveAttachmentUpdate($request, $leaveRequest, $companyId);

        $this->leaveBalances->replacePendingLeaveRequest($leaveRequest, [
            'employee_id' => $data['employee_id'],
            'leave_type_id' => $data['leave_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
        ]);

        $leaveRequest->update(array_merge([
            'employee_id' => $data['employee_id'],
            'leave_type_id' => $data['leave_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'total_days' => $calculateDays($data['start_date'], $data['end_date']),
            'reason' => $data['reason'] ?? null,
        ], $attachmentPayload));

        return redirect()
            ->route('attendance.leave-requests.index')
            ->with('success', 'Leave request updated successfully.');
    }

    public function destroy(LeaveRequest $leaveRequest): RedirectResponse
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($leaveRequest, request()->user(), $companyId);

        if (! in_array($leaveRequest->status, ['pending', 'cancelled'], true)) {
            return redirect()
                ->route('attendance.leave-requests.index')
                ->withErrors(['leave_request' => 'Only pending or cancelled leave requests can be deleted.']);
        }

        if ($leaveRequest->status === 'pending') {
            $this->leaveBalances->releaseLeaveRequest($leaveRequest);
        }

        $leaveRequest->delete();

        return redirect()
            ->route('attendance.leave-requests.index')
            ->with('success', 'Leave request deleted successfully.');
    }

    public function approve(ApproveLeaveRequestRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($leaveRequest, $request->user(), $companyId);

        if ($leaveRequest->status !== 'pending') {
            return redirect()
                ->route('attendance.leave-requests.index')
                ->withErrors(['leave_request' => 'Only pending leave requests can be approved.']);
        }

        $this->leaveBalances->approveLeaveRequest($leaveRequest);

        $leaveRequest->update([
            'status' => 'approved',
            'approved_by' => $request->user()?->id,
            'decided_at' => now(),
            'rejection_reason' => null,
            'cancellation_reason' => null,
        ]);

        app(SendLeaveRequestDecidedEmail::class)->handle($leaveRequest->fresh());

        return redirect()
            ->route('attendance.leave-requests.index')
            ->with('success', 'Leave request approved successfully.');
    }

    public function reject(RejectLeaveRequestRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($leaveRequest, $request->user(), $companyId);

        if ($leaveRequest->status !== 'pending') {
            return redirect()
                ->route('attendance.leave-requests.index')
                ->withErrors(['leave_request' => 'Only pending leave requests can be rejected.']);
        }

        $this->leaveBalances->releaseLeaveRequest($leaveRequest);

        $leaveRequest->update([
            'status' => 'rejected',
            'approved_by' => $request->user()?->id,
            'decided_at' => now(),
            'rejection_reason' => $request->validated('rejection_reason'),
        ]);

        app(SendLeaveRequestDecidedEmail::class)->handle($leaveRequest->fresh());

        return redirect()
            ->route('attendance.leave-requests.index')
            ->with('success', 'Leave request rejected successfully.');
    }

    public function cancel(CancelLeaveRequestRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($leaveRequest, $request->user(), $companyId);

        if ($leaveRequest->status !== 'pending') {
            return redirect()
                ->route('attendance.leave-requests.index')
                ->withErrors(['leave_request' => 'Only pending leave requests can be cancelled.']);
        }

        $this->leaveBalances->releaseLeaveRequest($leaveRequest);

        $leaveRequest->update([
            'status' => 'cancelled',
            'approved_by' => $request->user()?->id,
            'decided_at' => now(),
            'cancellation_reason' => $request->validated('cancellation_reason'),
        ]);

        return redirect()
            ->route('attendance.leave-requests.index')
            ->with('success', 'Leave request cancelled successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLeaveRequest(LeaveRequest $leaveRequest): array
    {
        return [
            'id' => $leaveRequest->id,
            'employee' => $leaveRequest->employee ? [
                'id' => $leaveRequest->employee->id,
                'employee_no' => $leaveRequest->employee->employee_no,
                'name' => $leaveRequest->employee->name,
            ] : null,
            'leave_type' => $leaveRequest->leaveType ? [
                'id' => $leaveRequest->leaveType->id,
                'name' => $leaveRequest->leaveType->name,
                'code' => $leaveRequest->leaveType->code,
                'color' => $leaveRequest->leaveType->color,
            ] : null,
            'start_date' => $leaveRequest->start_date?->toDateString(),
            'end_date' => $leaveRequest->end_date?->toDateString(),
            'total_days' => $leaveRequest->total_days,
            'reason' => $leaveRequest->reason,
            'status' => $leaveRequest->status,
            'rejection_reason' => $leaveRequest->rejection_reason,
            'cancellation_reason' => $leaveRequest->cancellation_reason,
            'decided_at' => $leaveRequest->decided_at?->toIso8601String(),
            'approver' => $leaveRequest->approver ? [
                'id' => $leaveRequest->approver->id,
                'name' => $leaveRequest->approver->name,
            ] : null,
            'created_at' => $leaveRequest->created_at?->toIso8601String(),
            'attachments' => $this->attachments->serializeForFrontend($leaveRequest->attachments, $leaveRequest->id),
        ];
    }

    /**
     * @return array{attachments?: list<array<string, mixed>>|null}
     */
    private function resolveAttachmentUpdate(Request $request, LeaveRequest $leaveRequest, int $companyId): array
    {
        if ($request->hasFile('attachment')) {
            $this->attachments->deleteFromStorage($leaveRequest->attachments);

            return [
                'attachments' => $this->attachments->store(
                    $request->file('attachment'),
                    $companyId,
                    $leaveRequest->id,
                ),
            ];
        }

        if ($request->boolean('remove_attachment')) {
            $this->attachments->deleteFromStorage($leaveRequest->attachments);

            return ['attachments' => null];
        }

        return [];
    }
}
