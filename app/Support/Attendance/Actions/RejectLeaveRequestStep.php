<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\User;
use App\Support\Attendance\AssertLeaveApprovalWorkflowInvariant;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RejectLeaveRequestStep
{
    public function __construct(
        private LeaveBalanceManager $leaveBalances,
        private LeaveRequestVisibility $visibility,
        private AssertLeaveApprovalWorkflowInvariant $assertInvariant,
        private SendLeaveRequestDecidedEmail $sendDecidedEmail,
    ) {}

    public function handle(
        LeaveRequest $leaveRequest,
        User $actor,
        int $companyId,
        string $rejectionReason,
        ?string $comments = null,
    ): LeaveRequest {
        $fresh = DB::transaction(function () use ($leaveRequest, $actor, $companyId, $rejectionReason, $comments): LeaveRequest {
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
                    'leave_request' => 'Only pending leave requests can be rejected.',
                ]);
            }

            if (! $this->visibility->canApproveCurrentStep($leaveRequest, $actor, $companyId)) {
                abort(403);
            }

            $allApprovals = LeaveRequestApproval::query()
                ->where('company_id', $companyId)
                ->where('leave_request_id', $leaveRequest->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            $pendingStep = $this->assertInvariant->forPendingRequest($leaveRequest, $allApprovals, $actor);

            $stepComments = filled($comments) ? trim($comments) : trim($rejectionReason);

            $pendingStep->forceFill([
                'status' => LeaveRequestApprovalStatus::Rejected,
                'acted_at' => now(),
                'comments' => $stepComments !== '' ? $stepComments : null,
            ])->save();

            $this->cancelOpenSteps($leaveRequest, $companyId);

            $this->leaveBalances->releaseLeaveRequest($leaveRequest);

            $leaveRequest->forceFill([
                'status' => 'rejected',
                'approved_by' => $actor->id,
                'decided_at' => now(),
                'rejection_reason' => $rejectionReason,
            ])->save();

            $fresh = $leaveRequest->fresh(['approvals', 'employee', 'leaveType', 'company']) ?? $leaveRequest;
            $this->assertInvariant->forTerminalRequest($fresh, $fresh->approvals);

            return $fresh;
        });

        DB::afterCommit(function () use ($fresh): void {
            try {
                $this->sendDecidedEmail->handle($fresh->fresh() ?? $fresh);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        return $fresh;
    }

    private function cancelOpenSteps(LeaveRequest $leaveRequest, int $companyId): void
    {
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
    }
}
