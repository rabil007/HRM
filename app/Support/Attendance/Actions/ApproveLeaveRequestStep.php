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

final class ApproveLeaveRequestStep
{
    public function __construct(
        private LeaveBalanceManager $leaveBalances,
        private LeaveRequestVisibility $visibility,
        private SendLeaveRequestApproverActionRequiredEmail $sendActionRequiredEmail,
        private SendLeaveRequestDecidedEmail $sendDecidedEmail,
    ) {}

    public function handle(
        LeaveRequest $leaveRequest,
        User $actor,
        int $companyId,
        ?string $comments = null,
    ): LeaveRequest {
        $outcome = DB::transaction(function () use ($leaveRequest, $actor, $companyId, $comments): array {
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
                    'leave_request' => 'Only pending leave requests can be approved.',
                ]);
            }

            if (! $this->visibility->canApproveCurrentStep($leaveRequest, $actor, $companyId)) {
                abort(403);
            }

            $pendingStep = LeaveRequestApproval::query()
                ->where('company_id', $companyId)
                ->where('leave_request_id', $leaveRequest->id)
                ->where('status', LeaveRequestApprovalStatus::Pending)
                ->where('approver_user_id', $actor->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->first();

            if ($pendingStep === null) {
                throw ValidationException::withMessages([
                    'leave_request' => 'There is no pending approval step assigned to you for this leave request.',
                ]);
            }

            $pendingStep->forceFill([
                'status' => LeaveRequestApprovalStatus::Approved,
                'acted_at' => now(),
                'comments' => filled($comments) ? trim($comments) : null,
            ])->save();

            $nextPending = $this->activateNextRequiredWaitingStep($leaveRequest, $companyId);

            if ($nextPending === null) {
                $this->leaveBalances->approveLeaveRequest($leaveRequest);

                $leaveRequest->forceFill([
                    'status' => 'approved',
                    'approved_by' => $actor->id,
                    'decided_at' => now(),
                    'rejection_reason' => null,
                    'cancellation_reason' => null,
                ])->save();

                return [
                    'leave_request' => $leaveRequest->fresh(['approvals', 'employee', 'leaveType', 'company']) ?? $leaveRequest,
                    'notify' => 'decided',
                ];
            }

            return [
                'leave_request' => $leaveRequest->fresh(['approvals', 'employee', 'leaveType', 'company']) ?? $leaveRequest,
                'notify' => 'next',
                'next_step_id' => $nextPending->id,
            ];
        });

        $fresh = $outcome['leave_request'];

        DB::afterCommit(function () use ($outcome, $fresh): void {
            try {
                if ($outcome['notify'] === 'decided') {
                    $this->sendDecidedEmail->handle($fresh->fresh() ?? $fresh);

                    return;
                }

                $this->sendActionRequiredEmail->handle($fresh->fresh() ?? $fresh);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        return $fresh;
    }

    private function activateNextRequiredWaitingStep(LeaveRequest $leaveRequest, int $companyId): ?LeaveRequestApproval
    {
        $waitingSteps = LeaveRequestApproval::query()
            ->where('company_id', $companyId)
            ->where('leave_request_id', $leaveRequest->id)
            ->where('status', LeaveRequestApprovalStatus::Waiting)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get();

        foreach ($waitingSteps as $step) {
            if (! $step->is_required) {
                $step->forceFill([
                    'status' => LeaveRequestApprovalStatus::Skipped,
                    'acted_at' => now(),
                ])->save();

                continue;
            }

            $step->forceFill([
                'status' => LeaveRequestApprovalStatus::Pending,
            ])->save();

            return $step;
        }

        return null;
    }
}
