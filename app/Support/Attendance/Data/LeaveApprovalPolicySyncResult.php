<?php

namespace App\Support\Attendance\Data;

/**
 * Result of synchronizing a leave approval policy to pending leave requests.
 *
 * @phpstan-type ResultArray array{
 *     policy_id: int,
 *     policy_name: string,
 *     synchronized_count: int,
 *     synchronized_leave_request_ids: list<int>,
 *     skipped_count: int,
 *     skipped_approval_started_count: int,
 *     skipped_completed_or_ineligible_count: int,
 *     skipped_race_count: int,
 *     failed_count: int,
 *     failed_leave_request_ids: list<int>
 * }
 */
final class LeaveApprovalPolicySyncResult
{
    /**
     * @param  list<int>  $synchronizedLeaveRequestIds
     * @param  list<int>  $failedLeaveRequestIds
     */
    public function __construct(
        public readonly int $policyId,
        public readonly string $policyName,
        public readonly int $synchronizedCount,
        public readonly array $synchronizedLeaveRequestIds,
        public readonly int $skippedCount,
        public readonly int $skippedApprovalStartedCount,
        public readonly int $skippedCompletedOrIneligibleCount,
        public readonly int $skippedRaceCount,
        public readonly int $failedCount,
        public readonly array $failedLeaveRequestIds,
    ) {}

    /**
     * @return ResultArray
     */
    public function toArray(): array
    {
        return [
            'policy_id' => $this->policyId,
            'policy_name' => $this->policyName,
            'synchronized_count' => $this->synchronizedCount,
            'synchronized_leave_request_ids' => $this->synchronizedLeaveRequestIds,
            'skipped_count' => $this->skippedCount,
            'skipped_approval_started_count' => $this->skippedApprovalStartedCount,
            'skipped_completed_or_ineligible_count' => $this->skippedCompletedOrIneligibleCount,
            'skipped_race_count' => $this->skippedRaceCount,
            'failed_count' => $this->failedCount,
            'failed_leave_request_ids' => $this->failedLeaveRequestIds,
        ];
    }

    public function flashMessage(): string
    {
        if ($this->synchronizedCount === 0 && $this->failedCount === 0) {
            return 'No eligible pending leave requests were found.';
        }

        $parts = [
            'Approval policy synchronized.',
        ];

        if ($this->synchronizedCount > 0) {
            $parts[] = sprintf(
                '%d pending leave request%s updated.',
                $this->synchronizedCount,
                $this->synchronizedCount === 1 ? '' : 's',
            );
        }

        if ($this->skippedRaceCount > 0) {
            $parts[] = sprintf(
                '%d request%s %s skipped because their approval state changed before synchronization.',
                $this->skippedRaceCount,
                $this->skippedRaceCount === 1 ? '' : 's',
                $this->skippedRaceCount === 1 ? 'was' : 'were',
            );
        }

        if ($this->failedCount > 0) {
            $parts[] = sprintf(
                '%d request%s could not be synchronized.',
                $this->failedCount,
                $this->failedCount === 1 ? '' : 's',
            );
        }

        return implode(' ', $parts);
    }
}
