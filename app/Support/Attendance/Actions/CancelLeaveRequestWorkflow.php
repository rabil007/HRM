<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\User;
use App\Support\Attendance\AssertLeaveApprovalWorkflowInvariant;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelLeaveRequestWorkflow
{
    public function __construct(
        private LeaveBalanceManager $leaveBalances,
        private AssertLeaveApprovalWorkflowInvariant $assertInvariant,
    ) {}

    public function handle(
        LeaveRequest $leaveRequest,
        User $actor,
        int $companyId,
        ?string $cancellationReason = null,
    ): LeaveRequest {
        return DB::transaction(function () use ($leaveRequest, $actor, $companyId, $cancellationReason): LeaveRequest {
            if ((int) $leaveRequest->company_id !== $companyId) {
                abort(404);
            }

            $leaveRequest = LeaveRequest::query()
                ->whereKey($leaveRequest->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($leaveRequest->status !== 'pending') {
                throw ValidationException::withMessages([
                    'leave_request' => 'Only pending leave requests can be cancelled.',
                ]);
            }

            LeaveRequestApproval::query()
                ->where('company_id', $companyId)
                ->where('leave_request_id', $leaveRequest->id)
                ->whereIn('status', [
                    LeaveRequestApprovalStatus::Waiting->value,
                    LeaveRequestApprovalStatus::Pending->value,
                ])
                ->lockForUpdate()
                ->get()
                ->each(function (LeaveRequestApproval $step): void {
                    $step->forceFill([
                        'status' => LeaveRequestApprovalStatus::Cancelled,
                        'acted_at' => now(),
                    ])->save();
                });

            $this->leaveBalances->releaseLeaveRequest($leaveRequest);

            $leaveRequest->forceFill([
                'status' => 'cancelled',
                'approved_by' => $actor->id,
                'decided_at' => now(),
                'cancellation_reason' => $cancellationReason,
            ])->save();

            $fresh = $leaveRequest->fresh(['approvals', 'employee', 'leaveType']) ?? $leaveRequest;
            $this->assertInvariant->forTerminalRequest($fresh, $fresh->approvals);

            return $fresh;
        });
    }
}
