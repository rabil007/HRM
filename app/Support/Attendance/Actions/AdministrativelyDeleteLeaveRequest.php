<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Privileged void-and-remove: soft-delete any leave request while reversing balance
 * and preserving approval history, attachments, and audit trail.
 */
final class AdministrativelyDeleteLeaveRequest
{
    public function __construct(
        private LeaveBalanceManager $leaveBalances,
    ) {}

    public function handle(
        LeaveRequest $leaveRequest,
        int $companyId,
        User $actor,
        string $reason,
    ): void {
        if ((int) $leaveRequest->company_id !== $companyId) {
            abort(404);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'administrative_deletion_reason' => 'A deletion reason is required.',
            ]);
        }

        DB::transaction(function () use ($leaveRequest, $companyId, $actor, $reason): void {
            $locked = LeaveRequest::withTrashed()
                ->whereKey($leaveRequest->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->trashed()) {
                throw ValidationException::withMessages([
                    'leave_request' => 'This leave request has already been removed.',
                ]);
            }

            $approvals = LeaveRequestApproval::query()
                ->where('company_id', $companyId)
                ->where('leave_request_id', $locked->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            $previousStatus = (string) $locked->status;
            $balancesBefore = $this->leaveBalances->snapshotAllocationBalances($locked, lock: true);

            match ($previousStatus) {
                'pending' => $this->leaveBalances->releasePendingReservation($locked),
                'approved' => $this->leaveBalances->releaseUsedAllocation($locked),
                'rejected', 'cancelled' => null,
                default => throw ValidationException::withMessages([
                    'leave_request' => 'This leave request has an unsupported status for administrative deletion.',
                ]),
            };

            if ($previousStatus === 'pending') {
                $approvals
                    ->filter(function (LeaveRequestApproval $step): bool {
                        $status = $step->status instanceof LeaveRequestApprovalStatus
                            ? $step->status
                            : LeaveRequestApprovalStatus::tryFrom((string) $step->status);

                        return $status === LeaveRequestApprovalStatus::Waiting
                            || $status === LeaveRequestApprovalStatus::Pending;
                    })
                    ->each(function (LeaveRequestApproval $step): void {
                        $step->forceFill([
                            'status' => LeaveRequestApprovalStatus::Cancelled,
                            'acted_at' => now(),
                        ])->save();
                    });
            }

            $balancesAfter = $this->leaveBalances->snapshotAllocationBalances($locked, lock: true);

            $locked->forceFill([
                'status_before_administrative_deletion' => $previousStatus,
                'administrative_deletion_reason' => $reason,
                'administratively_deleted_by' => $actor->id,
            ])->save();

            $locked->delete();

            $this->logAdministrativeDeletion(
                leaveRequest: $locked,
                companyId: $companyId,
                actor: $actor,
                reason: $reason,
                previousStatus: $previousStatus,
                balancesBefore: $balancesBefore,
                balancesAfter: $balancesAfter,
            );
        });
    }

    /**
     * @param  list<array{year: int, days: float, pending_days: float, used_days: float, remaining_days: float}>  $balancesBefore
     * @param  list<array{year: int, days: float, pending_days: float, used_days: float, remaining_days: float}>  $balancesAfter
     */
    private function logAdministrativeDeletion(
        LeaveRequest $leaveRequest,
        int $companyId,
        User $actor,
        string $reason,
        string $previousStatus,
        array $balancesBefore,
        array $balancesAfter,
    ): void {
        $activity = activity()
            ->performedOn($leaveRequest)
            ->causedBy($actor)
            ->withProperties([
                'event' => 'leave_request_administratively_deleted',
                'company_id' => $companyId,
                'leave_request_id' => (int) $leaveRequest->id,
                'employee_id' => (int) $leaveRequest->employee_id,
                'leave_type_id' => (int) $leaveRequest->leave_type_id,
                'actor_user_id' => (int) $actor->id,
                'deletion_reason' => $reason,
                'previous_status' => $previousStatus,
                'start_date' => $leaveRequest->start_date?->toDateString(),
                'end_date' => $leaveRequest->end_date?->toDateString(),
                'total_days' => (string) $leaveRequest->total_days,
                'balances_before' => $balancesBefore,
                'balances_after' => $balancesAfter,
                'timestamp' => now()->toIso8601String(),
            ])
            ->log('Leave request administratively voided and removed');

        $activity->forceFill(['company_id' => $companyId])->save();
    }
}
