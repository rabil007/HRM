<?php

namespace App\Support\Attendance;

use App\Enums\LeaveApprovalApproverType;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\User;
use App\Support\Attendance\Data\LeaveApprovalPolicySyncPreview;
use App\Support\Attendance\Data\LeaveApprovalPolicySyncResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class SyncLeaveApprovalPolicyToPendingRequests
{
    public function __construct(
        private PreviewLeaveApprovalPolicySync $preview,
        private EvaluateLeaveApprovalPolicySyncEligibility $eligibility,
        private ResolveLeaveApprovalChain $resolveChain,
        private AssertLeaveApprovalWorkflowInvariant $assertInvariant,
    ) {}

    public function handle(
        LeaveApprovalPolicy $policy,
        int $companyId,
        ?User $actor = null,
        ?LeaveApprovalPolicySyncPreview $preview = null,
    ): LeaveApprovalPolicySyncResult {
        if ((int) $policy->company_id !== $companyId) {
            abort(404);
        }

        $preview ??= $this->preview->handle($policy, $companyId);
        $candidateIds = $preview->eligibleLeaveRequestIds;

        $synchronizedIds = [];
        $skippedRace = 0;
        $failedIds = [];

        foreach ($candidateIds as $leaveRequestId) {
            try {
                $outcome = $this->syncOne(
                    leaveRequestId: $leaveRequestId,
                    policy: $policy,
                    companyId: $companyId,
                    actor: $actor,
                );

                if ($outcome === 'synchronized') {
                    $synchronizedIds[] = $leaveRequestId;
                } elseif ($outcome === 'skipped') {
                    $skippedRace++;
                }
            } catch (ValidationException $exception) {
                $failedIds[] = $leaveRequestId;
            } catch (Throwable $exception) {
                report($exception);
                $failedIds[] = $leaveRequestId;
            }
        }

        $result = new LeaveApprovalPolicySyncResult(
            policyId: (int) $policy->id,
            policyName: (string) $policy->name,
            synchronizedCount: count($synchronizedIds),
            synchronizedLeaveRequestIds: $synchronizedIds,
            skippedCount: $preview->skippedApprovalStartedCount
                + $preview->skippedCompletedOrIneligibleCount
                + $skippedRace
                + count($failedIds),
            skippedApprovalStartedCount: $preview->skippedApprovalStartedCount,
            skippedCompletedOrIneligibleCount: $preview->skippedCompletedOrIneligibleCount,
            skippedRaceCount: $skippedRace,
            failedCount: count($failedIds),
            failedLeaveRequestIds: $failedIds,
        );

        $this->logPolicySync(
            policy: $policy,
            companyId: $companyId,
            actor: $actor,
            preview: $preview,
            result: $result,
        );

        return $result;
    }

    /**
     * @return 'synchronized'|'skipped'
     */
    private function syncOne(
        int $leaveRequestId,
        LeaveApprovalPolicy $policy,
        int $companyId,
        ?User $actor,
    ): string {
        return DB::transaction(function () use ($leaveRequestId, $policy, $companyId, $actor): string {
            $locked = LeaveRequest::query()
                ->whereKey($leaveRequestId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return 'skipped';
            }

            $approvals = LeaveRequestApproval::query()
                ->where('company_id', $companyId)
                ->where('leave_request_id', $locked->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();

            if (! $this->eligibility->isEligible($locked, $policy, $companyId, $approvals)) {
                return 'skipped';
            }

            $previousApprovalSnapshot = $this->serializeApprovalsForAudit($approvals);

            LeaveRequestApproval::query()
                ->where('company_id', $companyId)
                ->where('leave_request_id', $locked->id)
                ->delete();

            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $locked->employee_id)
                ->firstOrFail();

            try {
                $chain = $this->resolveChain->handle($employee, $companyId);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'leave_request' => $exception->getMessage(),
                ]);
            }

            // Guard: resolution must still target this policy after locks.
            if ((int) $chain->policy->id !== (int) $policy->id) {
                throw ValidationException::withMessages([
                    'leave_request' => 'This leave request no longer matches the selected approval policy.',
                ]);
            }

            $created = $this->resolveChain->persistSnapshot($locked, $chain);

            if ($created === []) {
                throw ValidationException::withMessages([
                    'leave_request' => 'Unable to rebuild the leave approval chain for this request.',
                ]);
            }

            $fresh = $locked->fresh(['approvals', 'employee', 'leaveType', 'company']) ?? $locked;
            $this->assertInvariant->forPendingRequest($fresh, $fresh->approvals);

            $this->logChainRebuild(
                leaveRequest: $fresh,
                companyId: $companyId,
                actor: $actor,
                policy: $policy,
                previousApprovals: $previousApprovalSnapshot,
                newApprovals: $this->serializeApprovalsForAudit($fresh->approvals),
            );

            return 'synchronized';
        });
    }

    /**
     * @param  iterable<int, LeaveRequestApproval>  $approvals
     * @return list<array<string, mixed>>
     */
    private function serializeApprovalsForAudit(iterable $approvals): array
    {
        $rows = [];

        foreach ($approvals as $approval) {
            $approverType = $approval->approver_type;

            $rows[] = [
                'sequence' => (int) $approval->sequence,
                'approver_type' => $approverType instanceof LeaveApprovalApproverType
                    ? $approverType->value
                    : (string) $approverType,
                'approver_employee_id' => $approval->approver_employee_id !== null
                    ? (int) $approval->approver_employee_id
                    : null,
                'approver_user_id' => $approval->approver_user_id !== null
                    ? (int) $approval->approver_user_id
                    : null,
                'source_department_id' => $approval->source_department_id !== null
                    ? (int) $approval->source_department_id
                    : null,
                'policy_id' => $approval->policy_id !== null ? (int) $approval->policy_id : null,
                'status' => $approval->status instanceof \BackedEnum
                    ? $approval->status->value
                    : (string) $approval->status,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $previousApprovals
     * @param  list<array<string, mixed>>  $newApprovals
     */
    private function logChainRebuild(
        LeaveRequest $leaveRequest,
        int $companyId,
        ?User $actor,
        LeaveApprovalPolicy $policy,
        array $previousApprovals,
        array $newApprovals,
    ): void {
        $logger = activity()
            ->performedOn($leaveRequest)
            ->withProperties([
                'event' => 'leave_approval_chain_rebuilt',
                'company_id' => $companyId,
                'leave_request_id' => (int) $leaveRequest->id,
                'leave_approval_policy_id' => (int) $policy->id,
                'reason' => 'approval policy synchronized',
                'previous_approvals' => $previousApprovals,
                'new_approvals' => $newApprovals,
            ]);

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        $activity = $logger->log('Leave request approval chain rebuilt by policy synchronization');
        $activity->forceFill(['company_id' => $companyId])->save();
    }

    private function logPolicySync(
        LeaveApprovalPolicy $policy,
        int $companyId,
        ?User $actor,
        LeaveApprovalPolicySyncPreview $preview,
        LeaveApprovalPolicySyncResult $result,
    ): void {
        $logger = activity()
            ->performedOn($policy)
            ->withProperties([
                'event' => 'leave_approval_policy.pending_requests_synced',
                'company_id' => $companyId,
                'policy_id' => (int) $policy->id,
                'policy_name' => (string) $policy->name,
                'eligible_preview_count' => $preview->eligibleCount,
                'synchronized_count' => $result->synchronizedCount,
                'skipped_count' => $result->skippedCount,
                'skipped_approval_started_count' => $result->skippedApprovalStartedCount,
                'skipped_completed_or_ineligible_count' => $result->skippedCompletedOrIneligibleCount,
                'skipped_race_count' => $result->skippedRaceCount,
                'failed_count' => $result->failedCount,
                'synchronized_leave_request_ids' => $result->synchronizedLeaveRequestIds,
                'failed_leave_request_ids' => $result->failedLeaveRequestIds,
            ]);

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        $activity = $logger->log('Leave approval policy pending requests synchronized');
        $activity->forceFill(['company_id' => $companyId])->save();
    }
}
