<?php

namespace App\Support\Attendance\Data;

/**
 * Preview counts for synchronizing a leave approval policy to pending requests.
 *
 * @phpstan-type PreviewArray array{
 *     policy_id: int,
 *     policy_name: string,
 *     eligible_count: int,
 *     eligible_leave_request_ids: list<int>,
 *     skipped_approval_started_count: int,
 *     skipped_completed_or_ineligible_count: int
 * }
 */
final class LeaveApprovalPolicySyncPreview
{
    /**
     * @param  list<int>  $eligibleLeaveRequestIds
     */
    public function __construct(
        public readonly int $policyId,
        public readonly string $policyName,
        public readonly int $eligibleCount,
        public readonly array $eligibleLeaveRequestIds,
        public readonly int $skippedApprovalStartedCount,
        public readonly int $skippedCompletedOrIneligibleCount,
    ) {}

    /**
     * @return PreviewArray
     */
    public function toArray(): array
    {
        return [
            'policy_id' => $this->policyId,
            'policy_name' => $this->policyName,
            'eligible_count' => $this->eligibleCount,
            'eligible_leave_request_ids' => $this->eligibleLeaveRequestIds,
            'skipped_approval_started_count' => $this->skippedApprovalStartedCount,
            'skipped_completed_or_ineligible_count' => $this->skippedCompletedOrIneligibleCount,
        ];
    }
}
