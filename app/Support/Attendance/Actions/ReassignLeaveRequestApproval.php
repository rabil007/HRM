<?php

namespace App\Support\Attendance\Actions;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveRequestApprovalReassignment;
use App\Models\User;
use App\Support\Attendance\AssertLeaveApprovalWorkflowInvariant;
use App\Support\Attendance\LeaveApprovalApproverDuplicates;
use App\Support\Attendance\LeaveApproverEligibility;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Privileged recovery: reassign ONLY the current required Pending approval step.
 * Preserves prior Approved history, Waiting future steps, policy provenance, and balances.
 */
final class ReassignLeaveRequestApproval
{
    public function __construct(
        private AssertLeaveApprovalWorkflowInvariant $assertInvariant,
        private LeaveApproverEligibility $eligibility,
        private LeaveBalanceManager $leaveBalances,
        private SendLeaveRequestApproverActionRequiredEmail $sendActionRequiredEmail,
    ) {}

    public function handle(
        LeaveRequest $leaveRequest,
        int $companyId,
        User $actor,
        int $newApproverEmployeeId,
        string $reason,
        ?int $expectedApproverEmployeeId = null,
        ?int $expectedApprovalId = null,
    ): LeaveRequest {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reassignment_reason' => 'A reassignment reason is required.',
            ]);
        }

        $outcome = DB::transaction(function () use (
            $leaveRequest,
            $companyId,
            $actor,
            $newApproverEmployeeId,
            $reason,
            $expectedApproverEmployeeId,
            $expectedApprovalId,
        ): array {
            if ((int) $leaveRequest->company_id !== $companyId) {
                abort(404);
            }

            $locked = LeaveRequest::query()
                ->whereKey($leaveRequest->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'leave_request' => 'Only pending leave requests can have their current approval reassigned.',
                ]);
            }

            $approvals = LeaveRequestApproval::query()
                ->where('company_id', $companyId)
                ->where('leave_request_id', $locked->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            $pendingStep = $this->assertInvariant->forReassignment($locked, $approvals);

            if (
                $expectedApprovalId !== null
                && (int) $pendingStep->id !== $expectedApprovalId
            ) {
                throw ValidationException::withMessages([
                    'leave_request' => 'The approval workflow has changed. Refresh the request and try again.',
                ]);
            }

            if (
                $expectedApproverEmployeeId !== null
                && (int) ($pendingStep->approver_employee_id ?? 0) !== $expectedApproverEmployeeId
            ) {
                throw ValidationException::withMessages([
                    'leave_request' => 'The approval workflow has changed. Refresh the request and try again.',
                ]);
            }

            $status = $pendingStep->status instanceof LeaveRequestApprovalStatus
                ? $pendingStep->status
                : LeaveRequestApprovalStatus::tryFrom((string) $pendingStep->status);

            if ($status !== LeaveRequestApprovalStatus::Pending || ! $pendingStep->is_required) {
                throw ValidationException::withMessages([
                    'leave_request' => 'The approval workflow has changed. Refresh the request and try again.',
                ]);
            }

            [$fromEmployeeId, $fromUserId, $fromName] = $this->resolveCurrentApproverIdentity(
                companyId: $companyId,
                pendingStep: $pendingStep,
            );

            if ($fromEmployeeId !== null && $fromEmployeeId === $newApproverEmployeeId) {
                throw ValidationException::withMessages([
                    'new_approver_employee_id' => 'The selected employee is already the current approver.',
                ]);
            }

            $balancesBefore = $this->inspectPendingBalancesOrFail($locked);
            $balanceCountBefore = $this->countCompanyLeaveBalances($companyId);

            $newApprover = $this->resolveEligibleReplacement(
                leaveRequest: $locked,
                companyId: $companyId,
                approvals: $approvals,
                pendingStep: $pendingStep,
                newApproverEmployeeId: $newApproverEmployeeId,
            );

            $toName = (string) $newApprover->name;
            $actorName = (string) $actor->name;

            $pendingStep->forceFill([
                'approver_employee_id' => (int) $newApprover->id,
                'approver_user_id' => (int) $newApprover->user_id,
            ])->save();

            $history = LeaveRequestApprovalReassignment::query()->create([
                'company_id' => $companyId,
                'leave_request_id' => (int) $locked->id,
                'leave_request_approval_id' => (int) $pendingStep->id,
                'sequence' => (int) $pendingStep->sequence,
                'policy_step_label' => $pendingStep->policy_step_label,
                'from_approver_employee_id' => $fromEmployeeId,
                'from_approver_user_id' => $fromUserId,
                'from_approver_name' => $fromName,
                'to_approver_employee_id' => (int) $newApprover->id,
                'to_approver_user_id' => (int) $newApprover->user_id,
                'to_approver_name' => $toName,
                'reason' => $reason,
                'reassigned_by_user_id' => (int) $actor->id,
                'reassigned_by_name' => $actorName,
            ]);

            $balancesAfter = $this->inspectPendingBalancesOrFail($locked);
            $balanceCountAfter = $this->countCompanyLeaveBalances($companyId);

            if ($balancesBefore !== $balancesAfter || $balanceCountBefore !== $balanceCountAfter) {
                throw ValidationException::withMessages([
                    'leave_request' => 'Leave balances must not change during approval reassignment.',
                ]);
            }

            $fresh = $locked->fresh(['approvals', 'employee', 'leaveType', 'company']) ?? $locked;
            $this->assertInvariant->forPendingRequest($fresh, $fresh->approvals);

            $this->logReassignment(
                leaveRequest: $fresh,
                companyId: $companyId,
                actor: $actor,
                pendingStep: $pendingStep,
                history: $history,
                fromName: $fromName,
                toName: $toName,
                balancesBefore: $balancesBefore,
                balancesAfter: $balancesAfter,
            );

            return [
                'leave_request' => $fresh,
            ];
        });

        $fresh = $outcome['leave_request'];

        DB::afterCommit(function () use ($fresh): void {
            try {
                $this->sendActionRequiredEmail->handle($fresh->fresh(['approvals.approverEmployee.user', 'employee.department', 'leaveType', 'company']) ?? $fresh);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        return $fresh;
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: string}
     */
    private function resolveCurrentApproverIdentity(int $companyId, LeaveRequestApproval $pendingStep): array
    {
        if ((int) $pendingStep->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has a corrupted approval workflow (approval company mismatch).',
            ]);
        }

        $fromEmployeeId = $pendingStep->approver_employee_id !== null
            ? (int) $pendingStep->approver_employee_id
            : null;
        $fromUserId = $pendingStep->approver_user_id !== null
            ? (int) $pendingStep->approver_user_id
            : null;

        if ($fromEmployeeId === null) {
            return [null, $fromUserId, 'Unknown approver'];
        }

        $fromEmployee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($fromEmployeeId)
            ->first(['id', 'name', 'user_id', 'company_id']);

        if ($fromEmployee === null) {
            // Employee missing or belongs to another company — structural corruption.
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has a corrupted approval workflow (approver employee is not in the request company).',
            ]);
        }

        if (
            $fromUserId !== null
            && $fromEmployee->user_id !== null
            && (int) $fromEmployee->user_id !== $fromUserId
        ) {
            throw ValidationException::withMessages([
                'leave_request' => 'This leave request has a corrupted approval workflow (approver user does not match the linked employee).',
            ]);
        }

        return [
            $fromEmployeeId,
            $fromUserId,
            (string) ($fromEmployee->name !== '' && $fromEmployee->name !== null
                ? $fromEmployee->name
                : 'Unknown approver'),
        ];
    }

    /**
     * @param  Collection<int, LeaveRequestApproval>  $approvals
     */
    private function resolveEligibleReplacement(
        LeaveRequest $leaveRequest,
        int $companyId,
        Collection $approvals,
        LeaveRequestApproval $pendingStep,
        int $newApproverEmployeeId,
    ): Employee {
        $newApprover = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($newApproverEmployeeId)
            ->with('user:id,name,email,status')
            ->first();

        if ($newApprover === null) {
            throw ValidationException::withMessages([
                'new_approver_employee_id' => 'The selected employee was not found in this company.',
            ]);
        }

        if ((int) $newApprover->id === (int) $leaveRequest->employee_id) {
            throw ValidationException::withMessages([
                'new_approver_employee_id' => 'The leave requester cannot become an approver on their own request.',
            ]);
        }

        if (LeaveApprovalApproverDuplicates::containsEmployee(
            $approvals,
            $newApproverEmployeeId,
            exceptApprovalId: (int) $pendingStep->id,
        )) {
            throw ValidationException::withMessages([
                'new_approver_employee_id' => 'The selected employee is already an approver on this leave request.',
            ]);
        }

        $evaluation = $this->eligibility->evaluate($newApprover, $companyId);

        if (! $evaluation['actionable']) {
            $message = $evaluation['warnings'][0]
                ?? 'The selected employee is not an eligible leave approver.';

            throw ValidationException::withMessages([
                'new_approver_employee_id' => $message,
            ]);
        }

        if ($newApprover->user_id === null) {
            throw ValidationException::withMessages([
                'new_approver_employee_id' => 'The selected employee is not linked to a user account.',
            ]);
        }

        return $newApprover;
    }

    /**
     * @return list<array{year: int, days: float, pending_days: float, used_days: float, remaining_days: float}>
     */
    private function inspectPendingBalancesOrFail(LeaveRequest $leaveRequest): array
    {
        try {
            return $this->leaveBalances->assertPendingAllocationIntegrity($leaveRequest, lock: true);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'leave_request' => 'The leave balance reservation is inconsistent. Repair the leave balance before reassigning this approval.',
            ]);
        }
    }

    private function countCompanyLeaveBalances(int $companyId): int
    {
        return (int) DB::table('leave_balances')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * @param  list<array{year: int, days: float, pending_days: float, used_days: float, remaining_days: float}>  $balancesBefore
     * @param  list<array{year: int, days: float, pending_days: float, used_days: float, remaining_days: float}>  $balancesAfter
     */
    private function logReassignment(
        LeaveRequest $leaveRequest,
        int $companyId,
        User $actor,
        LeaveRequestApproval $pendingStep,
        LeaveRequestApprovalReassignment $history,
        string $fromName,
        string $toName,
        array $balancesBefore,
        array $balancesAfter,
    ): void {
        $activity = activity()
            ->performedOn($leaveRequest)
            ->causedBy($actor)
            ->withProperties([
                'event' => 'leave_request_approval_reassigned',
                'company_id' => $companyId,
                'leave_request_id' => (int) $leaveRequest->id,
                'leave_request_approval_id' => (int) $pendingStep->id,
                'reassignment_id' => (int) $history->id,
                'sequence' => (int) $pendingStep->sequence,
                'policy_step_label' => $pendingStep->policy_step_label,
                'from_approver_employee_id' => $history->from_approver_employee_id,
                'from_approver_user_id' => $history->from_approver_user_id,
                'from_approver_name' => $fromName,
                'to_approver_employee_id' => $history->to_approver_employee_id,
                'to_approver_user_id' => $history->to_approver_user_id,
                'to_approver_name' => $toName,
                'reason' => $history->reason,
                'reassigned_by_user_id' => (int) $actor->id,
                'reassigned_by_name' => $history->reassigned_by_name,
                'balances_before' => $balancesBefore,
                'balances_after' => $balancesAfter,
                'timestamp' => now()->toIso8601String(),
            ])
            ->log('Leave approval reassigned');

        $activity->forceFill(['company_id' => $companyId])->save();
    }
}
