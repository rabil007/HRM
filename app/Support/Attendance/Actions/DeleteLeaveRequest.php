<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAttachments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteLeaveRequest
{
    private const APPROVAL_STARTED_MESSAGE = 'This leave request cannot be deleted because the approval process has already started. Cancel it instead to preserve approval history.';

    public function __construct(
        private LeaveBalanceManager $leaveBalances,
        private LeaveRequestAttachments $attachments,
    ) {}

    public function handle(LeaveRequest $leaveRequest, int $companyId): void
    {
        if ((int) $leaveRequest->company_id !== $companyId) {
            abort(404);
        }

        $attachmentsToDelete = null;

        DB::transaction(function () use ($leaveRequest, $companyId, &$attachmentsToDelete): void {
            $locked = LeaveRequest::query()
                ->whereKey($leaveRequest->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, ['pending', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'leave_request' => 'Only pending or cancelled leave requests can be deleted.',
                ]);
            }

            $approvals = LeaveRequestApproval::query()
                ->where('company_id', $companyId)
                ->where('leave_request_id', $locked->id)
                ->lockForUpdate()
                ->get();

            if ($this->hasActedApprovals($approvals)) {
                throw ValidationException::withMessages([
                    'leave_request' => self::APPROVAL_STARTED_MESSAGE,
                ]);
            }

            $attachmentsToDelete = $locked->attachments;

            if ($locked->status === 'pending') {
                $this->leaveBalances->releaseLeaveRequest($locked);
            }

            LeaveRequestApproval::query()
                ->where('company_id', $companyId)
                ->where('leave_request_id', $locked->id)
                ->delete();

            $locked->delete();
        });

        $this->attachments->deleteFromStorage($attachmentsToDelete);
    }

    /**
     * @param  iterable<int, LeaveRequestApproval>  $approvals
     */
    private function hasActedApprovals(iterable $approvals): bool
    {
        foreach ($approvals as $approval) {
            $status = $approval->status instanceof LeaveRequestApprovalStatus
                ? $approval->status
                : LeaveRequestApprovalStatus::tryFrom((string) $approval->status);

            if ($status !== null && $status->isTerminal()) {
                return true;
            }
        }

        return false;
    }
}
