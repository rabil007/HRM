<?php

namespace App\Support\Attendance;

use App\Enums\LeaveApprovalMode;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Support\Attendance\Data\LeaveRequestEmailApprovalContext;
use Illuminate\Support\Collection;

/**
 * Builds leave-email approval display from the leave request snapshot.
 *
 * Never labels a generic pending approver as "Manager".
 */
final class PresentLeaveRequestEmailApprovalContext
{
    public const LABEL_CURRENT = 'Current approver';

    public const LABEL_APPROVERS = 'Approvers';

    public const HELP_ANY_REQUIRED = 'Any one of these approvers can act on this request.';

    public function handle(LeaveRequest $leaveRequest): LeaveRequestEmailApprovalContext
    {
        $managerName = ResolveLeaveRequestEmailDepartmentManagerName::handle($leaveRequest);
        $pendingNames = $this->pendingRequiredApproverNames($leaveRequest);

        if ($pendingNames === []) {
            return new LeaveRequestEmailApprovalContext(
                approvalLabel: null,
                approvalNames: [],
                approvalHelpText: null,
                managerName: $managerName,
                approverName: '',
                approverNames: '',
            );
        }

        $isAnyRequiredWithMultiple = $leaveRequest->approvalMode() === LeaveApprovalMode::AnyRequired
            && count($pendingNames) > 1;

        return new LeaveRequestEmailApprovalContext(
            approvalLabel: $isAnyRequiredWithMultiple ? self::LABEL_APPROVERS : self::LABEL_CURRENT,
            approvalNames: $pendingNames,
            approvalHelpText: $isAnyRequiredWithMultiple ? self::HELP_ANY_REQUIRED : null,
            managerName: $managerName,
            approverName: $pendingNames[0],
            approverNames: implode(', ', $pendingNames),
        );
    }

    /**
     * @return list<string>
     */
    private function pendingRequiredApproverNames(LeaveRequest $leaveRequest): array
    {
        /** @var Collection<int, LeaveRequestApproval> $pending */
        $pending = $leaveRequest->relationLoaded('approvals')
            ? $leaveRequest->approvals
                ->sortBy('sequence')
                ->filter(function (LeaveRequestApproval $approval) use ($leaveRequest): bool {
                    $status = $approval->status instanceof LeaveRequestApprovalStatus
                        ? $approval->status
                        : LeaveRequestApprovalStatus::tryFrom((string) $approval->status);

                    return (bool) $approval->is_required
                        && $status === LeaveRequestApprovalStatus::Pending
                        && (int) $approval->company_id === (int) $leaveRequest->company_id;
                })
                ->values()
            : LeaveRequestApproval::query()
                ->where('company_id', $leaveRequest->company_id)
                ->where('leave_request_id', $leaveRequest->id)
                ->where('is_required', true)
                ->where('status', LeaveRequestApprovalStatus::Pending)
                ->orderBy('sequence')
                ->with('approverEmployee:id,company_id,name')
                ->get();

        $names = [];
        $seenEmployeeIds = [];

        foreach ($pending as $approval) {
            $approval->loadMissing('approverEmployee:id,company_id,name');
            $employee = $approval->approverEmployee;

            if ($employee === null || ! filled($employee->name)) {
                continue;
            }

            if ((int) $employee->company_id !== (int) $leaveRequest->company_id) {
                continue;
            }

            $employeeId = (int) $employee->id;

            if (isset($seenEmployeeIds[$employeeId])) {
                continue;
            }

            $seenEmployeeIds[$employeeId] = true;
            $names[] = (string) $employee->name;
        }

        return $names;
    }
}
