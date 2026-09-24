<?php

namespace App\Support\Attendance;

use App\Enums\LeaveApprovalMode;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Enforces leave-approval snapshot sequence integrity under row locks.
 *
 * Structure depends on the leave request's snapshotted approval_mode, never
 * the live policy configuration.
 */
final class AssertLeaveApprovalWorkflowInvariant
{
    public function __construct(
        private ResolveCompanyAccess $companyAccess,
    ) {}

    /**
     * @param  Collection<int, LeaveRequestApproval>  $approvals  Locked, ordered by sequence
     *
     * @throws ValidationException
     */
    public function forPendingRequest(
        LeaveRequest $leaveRequest,
        Collection $approvals,
        ?User $actor = null,
    ): LeaveRequestApproval {
        $mode = $leaveRequest->approvalMode();

        $pendingStep = $mode === LeaveApprovalMode::AnyRequired
            ? $this->assertAnyRequiredPendingStructure(
                leaveRequest: $leaveRequest,
                approvals: $approvals,
                actor: $actor,
            )
            : $this->assertAllRequiredPendingStructure(
                leaveRequest: $leaveRequest,
                approvals: $approvals,
                allowUnavailableCurrentApprover: false,
            );

        $this->assertPendingApproverIntegrity($leaveRequest, $pendingStep);

        if ($actor !== null && (int) $pendingStep->approver_user_id !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'leave_request' => 'There is no pending approval step assigned to you for this leave request.',
            ]);
        }

        return $pendingStep;
    }

    /**
     * Structural pending-workflow checks for administrative reassignment.
     *
     * Available only for all_required sequences (exactly one current pending step).
     *
     * @param  Collection<int, LeaveRequestApproval>  $approvals  Locked, ordered by sequence
     *
     * @throws ValidationException
     */
    public function forReassignment(
        LeaveRequest $leaveRequest,
        Collection $approvals,
    ): LeaveRequestApproval {
        if ($leaveRequest->approvalMode() === LeaveApprovalMode::AnyRequired) {
            throw ValidationException::withMessages([
                'leave_request' => 'Approval reassignment is not available when the leave request uses the any one required approver mode.',
            ]);
        }

        return $this->assertAllRequiredPendingStructure(
            leaveRequest: $leaveRequest,
            approvals: $approvals,
            allowUnavailableCurrentApprover: true,
        );
    }

    /**
     * @param  Collection<int, LeaveRequestApproval>  $approvals
     *
     * @throws ValidationException
     */
    private function assertAllRequiredPendingStructure(
        LeaveRequest $leaveRequest,
        Collection $approvals,
        bool $allowUnavailableCurrentApprover,
    ): LeaveRequestApproval {
        if ($leaveRequest->status !== 'pending') {
            throw ValidationException::withMessages([
                'leave_request' => $allowUnavailableCurrentApprover
                    ? 'Only pending leave requests can have their current approval reassigned.'
                    : 'Leave approval workflow invariant requires a pending leave request.',
            ]);
        }

        if ($approvals->isEmpty()) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has no approval snapshot.',
            ]);
        }

        $ordered = $approvals->sortBy('sequence')->values();
        $pending = $ordered->filter(fn (LeaveRequestApproval $step): bool => $this->statusOf($step) === LeaveRequestApprovalStatus::Pending)->values();

        if ($pending->count() !== 1) {
            throw ValidationException::withMessages([
                'leave_request' => $allowUnavailableCurrentApprover
                    ? 'The approval workflow has changed. Refresh the request and try again.'
                    : 'This leave request has a corrupted approval workflow (expected exactly one pending step).',
            ]);
        }

        /** @var LeaveRequestApproval $pendingStep */
        $pendingStep = $pending->first();

        if (! $pendingStep->is_required) {
            throw ValidationException::withMessages([
                'leave_request' => $allowUnavailableCurrentApprover
                    ? 'Only the current required pending approval step can be reassigned.'
                    : 'This leave request has a corrupted approval workflow (optional step cannot be pending).',
            ]);
        }

        $this->assertSharedApproverIdentityIntegrity($leaveRequest, $ordered);

        $seenPending = false;

        foreach ($ordered as $step) {
            $status = $this->statusOf($step);

            if ($status === LeaveRequestApprovalStatus::Pending) {
                $seenPending = true;

                continue;
            }

            if (! $seenPending) {
                if ($step->is_required && $status !== LeaveRequestApprovalStatus::Approved) {
                    throw ValidationException::withMessages([
                        'leave_request' => 'This leave request has a corrupted approval workflow (earlier required step is not approved).',
                    ]);
                }

                if (! $step->is_required && $status !== LeaveRequestApprovalStatus::Skipped) {
                    throw ValidationException::withMessages([
                        'leave_request' => 'This leave request has a corrupted approval workflow (leading optional steps must be skipped).',
                    ]);
                }

                continue;
            }

            // After the pending step.
            if ($step->is_required) {
                if ($status !== LeaveRequestApprovalStatus::Waiting) {
                    throw ValidationException::withMessages([
                        'leave_request' => 'This leave request has a corrupted approval workflow (later required step must be waiting).',
                    ]);
                }
            } elseif (! in_array($status, [
                LeaveRequestApprovalStatus::Waiting,
                LeaveRequestApprovalStatus::Skipped,
            ], true)) {
                throw ValidationException::withMessages([
                    'leave_request' => 'This leave request has a corrupted approval workflow (unexpected later optional step status).',
                ]);
            }
        }

        return $pendingStep;
    }

    /**
     * @param  Collection<int, LeaveRequestApproval>  $approvals
     *
     * @throws ValidationException
     */
    private function assertAnyRequiredPendingStructure(
        LeaveRequest $leaveRequest,
        Collection $approvals,
        ?User $actor,
    ): LeaveRequestApproval {
        if ($leaveRequest->status !== 'pending') {
            throw ValidationException::withMessages([
                'leave_request' => 'Leave approval workflow invariant requires a pending leave request.',
            ]);
        }

        if ($approvals->isEmpty()) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has no approval snapshot.',
            ]);
        }

        $ordered = $approvals->sortBy('sequence')->values();
        $this->assertSharedApproverIdentityIntegrity($leaveRequest, $ordered);

        $pendingRequired = $ordered
            ->filter(function (LeaveRequestApproval $step): bool {
                return (bool) $step->is_required
                    && $this->statusOf($step) === LeaveRequestApprovalStatus::Pending;
            })
            ->values();

        if ($pendingRequired->isEmpty()) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has a corrupted approval workflow (expected one or more pending required steps).',
            ]);
        }

        foreach ($ordered as $step) {
            $status = $this->statusOf($step);

            if (! $step->is_required) {
                if ($status !== LeaveRequestApprovalStatus::Skipped) {
                    throw ValidationException::withMessages([
                        'leave_request' => 'This leave request has a corrupted approval workflow (notify-only steps must be skipped).',
                    ]);
                }

                continue;
            }

            if ($status !== LeaveRequestApprovalStatus::Pending) {
                throw ValidationException::withMessages([
                    'leave_request' => 'This leave request has a corrupted approval workflow (any-required pending requests must keep every required step pending).',
                ]);
            }
        }

        if ($actor === null) {
            /** @var LeaveRequestApproval */
            return $pendingRequired->first();
        }

        $actorStep = $pendingRequired->first(
            fn (LeaveRequestApproval $step): bool => (int) $step->approver_user_id === (int) $actor->id,
        );

        if ($actorStep === null) {
            throw ValidationException::withMessages([
                'leave_request' => 'There is no pending approval step assigned to you for this leave request.',
            ]);
        }

        return $actorStep;
    }

    /**
     * @param  Collection<int, LeaveRequestApproval>  $ordered
     *
     * @throws ValidationException
     */
    private function assertSharedApproverIdentityIntegrity(LeaveRequest $leaveRequest, Collection $ordered): void
    {
        $seenApproverEmployeeIds = [];
        $requesterEmployeeId = (int) $leaveRequest->employee_id;
        $companyId = (int) $leaveRequest->company_id;

        foreach ($ordered as $step) {
            if ((int) $step->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'leave_request' => 'This leave request has a corrupted approval workflow (approval company mismatch).',
                ]);
            }

            if ($step->approver_employee_id !== null && (int) $step->approver_employee_id === $requesterEmployeeId) {
                throw ValidationException::withMessages([
                    'leave_request' => 'This leave request has a corrupted approval workflow (requester cannot be an approver).',
                ]);
            }

            if ($step->approver_employee_id !== null) {
                $employeeId = (int) $step->approver_employee_id;
                if (isset($seenApproverEmployeeIds[$employeeId])) {
                    throw ValidationException::withMessages([
                        'leave_request' => 'This leave request has a corrupted approval workflow (duplicate approvers).',
                    ]);
                }
                $seenApproverEmployeeIds[$employeeId] = true;
            }
        }
    }

    /**
     * @param  Collection<int, LeaveRequestApproval>  $approvals
     *
     * @throws ValidationException
     */
    public function forTerminalRequest(LeaveRequest $leaveRequest, Collection $approvals): void
    {
        if (! in_array($leaveRequest->status, ['approved', 'rejected', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'leave_request' => 'Leave approval terminal invariant requires an approved, rejected, or cancelled request.',
            ]);
        }

        $open = $approvals->first(function (LeaveRequestApproval $step): bool {
            $status = $this->statusOf($step);

            return $status === LeaveRequestApprovalStatus::Pending
                || $status === LeaveRequestApprovalStatus::Waiting;
        });

        if ($open !== null) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has a corrupted approval workflow (terminal request still has open steps).',
            ]);
        }

        if ($leaveRequest->approvalMode() === LeaveApprovalMode::AnyRequired
            && in_array($leaveRequest->status, ['approved', 'rejected'], true)) {
            $this->assertAnyRequiredTerminalShape($leaveRequest, $approvals);
        }
    }

    /**
     * @param  Collection<int, LeaveRequestApproval>  $approvals
     *
     * @throws ValidationException
     */
    private function assertAnyRequiredTerminalShape(LeaveRequest $leaveRequest, Collection $approvals): void
    {
        $ordered = $approvals->sortBy('sequence')->values();
        $this->assertSharedApproverIdentityIntegrity($leaveRequest, $ordered);

        $decidingStatus = $leaveRequest->status === 'approved'
            ? LeaveRequestApprovalStatus::Approved
            : LeaveRequestApprovalStatus::Rejected;

        $deciding = 0;

        foreach ($ordered as $step) {
            $status = $this->statusOf($step);

            if (! $step->is_required) {
                if ($status !== LeaveRequestApprovalStatus::Skipped) {
                    throw ValidationException::withMessages([
                        'leave_request' => 'This leave request has a corrupted approval workflow (notify-only steps must be skipped).',
                    ]);
                }

                continue;
            }

            if ($status === $decidingStatus) {
                $deciding++;

                continue;
            }

            if ($status !== LeaveRequestApprovalStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'leave_request' => 'This leave request has a corrupted approval workflow (non-deciding required steps must be cancelled).',
                ]);
            }
        }

        if ($deciding !== 1) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has a corrupted approval workflow (any-required terminal requests need exactly one deciding required step).',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertPendingApproverIntegrity(LeaveRequest $leaveRequest, LeaveRequestApproval $pendingStep): void
    {
        if ($pendingStep->approver_employee_id === null || $pendingStep->approver_user_id === null) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has a corrupted approval workflow (pending step is missing approver identity).',
            ]);
        }

        $companyId = (int) $leaveRequest->company_id;
        $approverEmployeeId = (int) $pendingStep->approver_employee_id;
        $approverUserId = (int) $pendingStep->approver_user_id;

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($approverEmployeeId)
            ->first(['id', 'company_id', 'user_id']);

        if ($employee === null) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has a corrupted approval workflow (approver employee is not in the request company).',
            ]);
        }

        if ($employee->user_id === null || (int) $employee->user_id !== $approverUserId) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has a corrupted approval workflow (approver user does not match the linked employee).',
            ]);
        }

        $user = User::query()->whereKey($approverUserId)->first();

        if ($user === null || ! $this->companyAccess->hasAccessibleMembership($user, $companyId)) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has a corrupted approval workflow (approver lacks accessible company membership).',
            ]);
        }
    }

    private function statusOf(LeaveRequestApproval $step): ?LeaveRequestApprovalStatus
    {
        return $step->status instanceof LeaveRequestApprovalStatus
            ? $step->status
            : LeaveRequestApprovalStatus::tryFrom((string) $step->status);
    }
}
