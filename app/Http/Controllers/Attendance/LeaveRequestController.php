<?php

namespace App\Http\Controllers\Attendance;

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ApproveLeaveRequestRequest;
use App\Http\Requests\Attendance\CancelLeaveRequestRequest;
use App\Http\Requests\Attendance\RejectLeaveRequestRequest;
use App\Http\Requests\Attendance\StoreLeaveRequestRequest;
use App\Http\Requests\Attendance\UpdateLeaveRequestRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\CancelLeaveRequestWorkflow;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\Actions\UpdateLeaveRequestWithApprovals;
use App\Support\Attendance\CalculateLeaveRequestDays;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAttachments;
use App\Support\Attendance\LeaveRequestVisibility;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

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
        $scope = $this->resolveScope($request);

        $user = $request->user();
        $canViewAll = $this->visibility->canViewAll($user);
        $linkedEmployeeId = $this->visibility->linkedEmployeeId($user, $companyId);

        if ($scope === 'all' && ! $canViewAll) {
            $scope = 'my';
        }

        $paginator = LeaveRequest::query()
            ->with([
                'employee:id,company_id,employee_no,name',
                'leaveType:id,company_id,name,code,color',
                'approver:id,name',
                'approvals' => fn ($query) => $query
                    ->orderBy('sequence')
                    ->with([
                        'approverEmployee:id,name,employee_no',
                        'approverUser:id,name',
                        'sourceDepartment:id,name',
                    ]),
            ])
            ->where('company_id', $companyId)
            ->tap(fn ($query) => $this->applyScopeFilter($query, $scope, $user, $companyId, $linkedEmployeeId, $canViewAll))
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

        $leaveRequests = $paginator->through(fn (LeaveRequest $leaveRequest) => $this->serializeLeaveRequest(
            $leaveRequest,
            $user,
            $companyId,
        ));

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
            ->tap(fn ($query) => $this->applyScopeFilter($query, $scope, $user, $companyId, $linkedEmployeeId, $canViewAll))
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
                'scope' => $scope,
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
                'view_all' => $canViewAll,
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
            'approvals' => fn ($query) => $query
                ->orderBy('sequence')
                ->with([
                    'approverEmployee:id,name,employee_no',
                    'approverUser:id,name',
                    'sourceDepartment:id,name',
                ]),
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
            'leave_request' => $this->serializeLeaveRequest($leaveRequest, $user, $companyId, includeApprovals: true),
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
                'approve_current_step' => $this->visibility->canApproveCurrentStep($leaveRequest, $user, $companyId),
                'view_all' => $canViewAll,
            ],
        ]);
    }

    public function store(
        StoreLeaveRequestRequest $request,
        CalculateLeaveRequestDays $calculateDays,
        SubmitLeaveRequestWithApprovals $submitWithApprovals,
    ): RedirectResponse {
        $data = $request->validated();
        $companyId = (int) $request->attributes->get('current_company_id');

        try {
            $leaveRequest = $submitWithApprovals->handle($companyId, null, [
                'employee_id' => $data['employee_id'],
                'leave_type_id' => $data['leave_type_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'total_days' => $calculateDays($data['start_date'], $data['end_date']),
                'reason' => $data['reason'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'leave_request' => $exception->getMessage(),
            ]);
        }

        if ($request->hasFile('attachment')) {
            $leaveRequest->update([
                'attachments' => $this->attachments->store(
                    $request->file('attachment'),
                    $companyId,
                    $leaveRequest->id,
                ),
            ]);
        }

        return redirect()
            ->route('attendance.leave-requests.index')
            ->with('success', 'Leave request created successfully.');
    }

    public function update(
        UpdateLeaveRequestRequest $request,
        LeaveRequest $leaveRequest,
        UpdateLeaveRequestWithApprovals $updateWithApprovals,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($leaveRequest, $request->user(), $companyId);

        $data = $request->validated();

        try {
            $updateWithApprovals->handle(
                leaveRequest: $leaveRequest,
                companyId: $companyId,
                attributes: [
                    'employee_id' => $data['employee_id'],
                    'leave_type_id' => $data['leave_type_id'],
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'reason' => $data['reason'] ?? null,
                ],
                newAttachment: $request->file('attachment'),
                removeAttachment: $request->boolean('remove_attachment'),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'leave_request' => $exception->getMessage(),
            ]);
        }

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

    public function approve(
        ApproveLeaveRequestRequest $request,
        LeaveRequest $leaveRequest,
        ApproveLeaveRequestStep $approveStep,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($leaveRequest, $request->user(), $companyId);

        $approveStep->handle(
            $leaveRequest,
            $request->user(),
            $companyId,
            $request->validated('comments'),
        );

        return redirect()
            ->route('attendance.leave-requests.index')
            ->with('success', 'Leave request approved successfully.');
    }

    public function reject(
        RejectLeaveRequestRequest $request,
        LeaveRequest $leaveRequest,
        RejectLeaveRequestStep $rejectStep,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($leaveRequest, $request->user(), $companyId);

        $rejectStep->handle(
            $leaveRequest,
            $request->user(),
            $companyId,
            $request->validated('rejection_reason'),
        );

        return redirect()
            ->route('attendance.leave-requests.index')
            ->with('success', 'Leave request rejected successfully.');
    }

    public function cancel(
        CancelLeaveRequestRequest $request,
        LeaveRequest $leaveRequest,
        CancelLeaveRequestWorkflow $cancelWorkflow,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $this->visibility->assertCanAccess($leaveRequest, $request->user(), $companyId);

        $cancelWorkflow->handle(
            $leaveRequest,
            $request->user(),
            $companyId,
            $request->validated('cancellation_reason'),
        );

        return redirect()
            ->route('attendance.leave-requests.index')
            ->with('success', 'Leave request cancelled successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLeaveRequest(
        LeaveRequest $leaveRequest,
        mixed $user = null,
        ?int $companyId = null,
        bool $includeApprovals = false,
    ): array {
        $payload = [
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
            'can_approve_current_step' => $companyId !== null
                ? $this->visibility->canApproveCurrentStep($leaveRequest, $user, $companyId)
                : false,
            'can_edit' => $this->canEditLeaveRequest($leaveRequest),
        ];

        if ($includeApprovals || $leaveRequest->relationLoaded('approvals')) {
            $payload['approvals'] = $leaveRequest->approvals
                ->map(fn (LeaveRequestApproval $approval) => $this->serializeApproval($approval))
                ->values()
                ->all();
        }

        return $payload;
    }

    private function canEditLeaveRequest(LeaveRequest $leaveRequest): bool
    {
        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        $hasStarted = LeaveRequestApproval::query()
            ->where('company_id', (int) $leaveRequest->company_id)
            ->where('leave_request_id', $leaveRequest->id)
            ->whereIn('status', [
                LeaveRequestApprovalStatus::Approved->value,
                LeaveRequestApprovalStatus::Rejected->value,
                LeaveRequestApprovalStatus::Skipped->value,
                LeaveRequestApprovalStatus::Cancelled->value,
            ])
            ->exists();

        return ! $hasStarted;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeApproval(LeaveRequestApproval $approval): array
    {
        $approverType = $approval->approver_type;

        return [
            'id' => $approval->id,
            'sequence' => $approval->sequence,
            'approver_type' => $approverType instanceof LeaveApprovalApproverType
                ? $approverType->value
                : $approverType,
            'approver_type_label' => $approverType instanceof LeaveApprovalApproverType
                ? $approverType->label()
                : null,
            'status' => $approval->status instanceof LeaveRequestApprovalStatus
                ? $approval->status->value
                : $approval->status,
            'is_required' => (bool) $approval->is_required,
            'acted_at' => $approval->acted_at?->toIso8601String(),
            'comments' => $approval->comments,
            'approver_employee' => $approval->approverEmployee ? [
                'id' => $approval->approverEmployee->id,
                'name' => $approval->approverEmployee->name,
                'employee_no' => $approval->approverEmployee->employee_no,
            ] : null,
            'approver_user' => $approval->approverUser ? [
                'id' => $approval->approverUser->id,
                'name' => $approval->approverUser->name,
            ] : null,
            'source_department' => $approval->sourceDepartment ? [
                'id' => $approval->sourceDepartment->id,
                'name' => $approval->sourceDepartment->name,
            ] : null,
            'policy_id' => $approval->policy_id,
            'policy_name' => $approval->policy_name,
            'policy_step_label' => $approval->policy_step_label,
        ];
    }

    private function resolveScope(Request $request): string
    {
        $scope = trim((string) $request->query('scope', 'my'));

        return in_array($scope, ['my', 'awaiting_my_approval', 'assigned_to_me', 'all'], true)
            ? $scope
            : 'my';
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    private function applyScopeFilter(
        $query,
        string $scope,
        mixed $user,
        int $companyId,
        ?int $linkedEmployeeId,
        bool $canViewAll,
    ): void {
        if ($scope === 'all' && $canViewAll) {
            return;
        }

        if ($scope === 'awaiting_my_approval') {
            if ($user === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $this->visibility->applyAwaitingMyApprovalScope($query, $user, $companyId);

            return;
        }

        if ($scope === 'assigned_to_me') {
            if ($user === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $this->visibility->applyAssignedToMeScope($query, $user, $companyId);

            return;
        }

        // Default / my
        if ($linkedEmployeeId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('employee_id', $linkedEmployeeId);
    }
}
