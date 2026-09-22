<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use Illuminate\Support\Collection;

/**
 * Shared eligibility rules for previewing and executing approval-policy sync.
 */
final class EvaluateLeaveApprovalPolicySyncEligibility
{
    public const REASON_ELIGIBLE = 'eligible';

    public const REASON_APPROVAL_STARTED = 'approval_already_started';

    public const REASON_NOT_PENDING = 'completed_or_cancelled';

    public const REASON_POLICY_MISMATCH = 'policy_mismatch';

    public const REASON_MISSING_EMPLOYEE = 'missing_employee';

    public function __construct(
        private ResolveLeaveApprovalPolicy $resolvePolicy,
    ) {}

    /**
     * Whether this leave request currently resolves to the given policy and
     * can safely have its unacted approval snapshot rebuilt.
     *
     * @param  Collection<int, LeaveRequestApproval>|null  $approvals
     */
    public function isEligible(
        LeaveRequest $leaveRequest,
        LeaveApprovalPolicy $policy,
        int $companyId,
        ?Collection $approvals = null,
    ): bool {
        return $this->classify($leaveRequest, $policy, $companyId, $approvals) === self::REASON_ELIGIBLE;
    }

    /**
     * @param  Collection<int, LeaveRequestApproval>|null  $approvals
     * @return self::REASON_*
     */
    public function classify(
        LeaveRequest $leaveRequest,
        LeaveApprovalPolicy $policy,
        int $companyId,
        ?Collection $approvals = null,
    ): string {
        if ((int) $leaveRequest->company_id !== $companyId) {
            return self::REASON_POLICY_MISMATCH;
        }

        if ($leaveRequest->trashed()) {
            return self::REASON_NOT_PENDING;
        }

        if ($leaveRequest->status !== 'pending') {
            return self::REASON_NOT_PENDING;
        }

        if (! $this->matchesPolicy($leaveRequest, $policy, $companyId)) {
            return self::REASON_POLICY_MISMATCH;
        }

        $approvals ??= $this->approvalsFor($leaveRequest, $companyId);

        if (LeaveRequestAuthorization::requiredApprovalHasBeenActed($approvals)) {
            return self::REASON_APPROVAL_STARTED;
        }

        return self::REASON_ELIGIBLE;
    }

    public function matchesPolicy(
        LeaveRequest $leaveRequest,
        LeaveApprovalPolicy $policy,
        int $companyId,
    ): bool {
        $employee = $leaveRequest->relationLoaded('employee')
            ? $leaveRequest->employee
            : Employee::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $leaveRequest->employee_id)
                ->first();

        if ($employee === null || (int) $employee->company_id !== $companyId) {
            return false;
        }

        $effective = $this->resolvePolicy->forEmployee($employee, $companyId);

        return $effective !== null && (int) $effective->policy->id === (int) $policy->id;
    }

    /**
     * @return Collection<int, LeaveRequestApproval>
     */
    private function approvalsFor(LeaveRequest $leaveRequest, int $companyId): Collection
    {
        if ($leaveRequest->relationLoaded('approvals')) {
            return $leaveRequest->approvals;
        }

        return LeaveRequestApproval::query()
            ->where('company_id', $companyId)
            ->where('leave_request_id', $leaveRequest->id)
            ->orderBy('sequence')
            ->get();
    }
}
