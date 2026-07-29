<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\User;
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

            $pendingSteps = $allApprovals->filter(
                function (LeaveRequestApproval $approval): bool {
                    $status = $approval->status instanceof LeaveRequestApprovalStatus
                        ? $approval->status
                        : LeaveRequestApprovalStatus::tryFrom((string) $approval->status);

                    return $status === LeaveRequestApprovalStatus::Pending;
                },
            )->values();

            if ($pendingSteps->count() !== 1) {
                throw ValidationException::withMessages([
                    'leave_request' => 'This leave request has a corrupted approval workflow (expected exactly one pending step). Contact an administrator.',
                ]);
            }

            /** @var LeaveRequestApproval $pendingStep */
            $pendingStep = $pendingSteps->first();

            if ((int) $pendingStep->approver_user_id !== (int) $actor->id) {
                throw ValidationException::withMessages([
                    'leave_request' => 'There is no pending approval step assigned to you for this leave request.',
                ]);
            }

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

            return $leaveRequest->fresh(['approvals', 'employee', 'leaveType', 'company']) ?? $leaveRequest;
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
