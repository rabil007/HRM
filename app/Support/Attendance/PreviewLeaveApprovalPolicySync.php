<?php

namespace App\Support\Attendance;

use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveRequest;
use App\Support\Attendance\Data\LeaveApprovalPolicySyncPreview;

final class PreviewLeaveApprovalPolicySync
{
    public function __construct(
        private EvaluateLeaveApprovalPolicySyncEligibility $eligibility,
    ) {}

    public function handle(LeaveApprovalPolicy $policy, int $companyId): LeaveApprovalPolicySyncPreview
    {
        if ((int) $policy->company_id !== $companyId) {
            abort(404);
        }

        $eligibleIds = [];
        $approvalStarted = 0;
        $completedOrIneligible = 0;

        $pendingRequests = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->with([
                'employee:id,company_id,department_id',
                'approvals' => fn ($query) => $query->orderBy('sequence'),
            ])
            ->orderBy('id')
            ->get();

        foreach ($pendingRequests as $leaveRequest) {
            $reason = $this->eligibility->classify($leaveRequest, $policy, $companyId, $leaveRequest->approvals);

            match ($reason) {
                EvaluateLeaveApprovalPolicySyncEligibility::REASON_ELIGIBLE => $eligibleIds[] = (int) $leaveRequest->id,
                EvaluateLeaveApprovalPolicySyncEligibility::REASON_APPROVAL_STARTED => $approvalStarted++,
                default => null,
            };
        }

        // Completed / cancelled requests that historically used this policy snapshot.
        $completedOrIneligible = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['approved', 'rejected', 'cancelled'])
            ->whereHas('approvals', function ($query) use ($companyId, $policy): void {
                $query->where('company_id', $companyId)
                    ->where('policy_id', $policy->id);
            })
            ->count();

        return new LeaveApprovalPolicySyncPreview(
            policyId: (int) $policy->id,
            policyName: (string) $policy->name,
            eligibleCount: count($eligibleIds),
            eligibleLeaveRequestIds: $eligibleIds,
            skippedApprovalStartedCount: $approvalStarted,
            skippedCompletedOrIneligibleCount: $completedOrIneligible,
        );
    }
}
