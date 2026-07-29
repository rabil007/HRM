<?php

namespace App\Support\Attendance\Actions;

use App\Models\Company;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveApprovalPolicyStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Diff-based policy step synchronization under company + policy locks.
 * Existing steps are matched by stable ID so reordering preserves identity.
 */
final class SyncLeaveApprovalPolicySteps
{
    /**
     * @param  list<array{
     *     id?: int|null,
     *     approver_type: string,
     *     approver_employee_id?: int|null,
     *     is_required?: bool
     * }>  $steps
     */
    public function handle(LeaveApprovalPolicy $policy, int $companyId, array $steps): void
    {
        DB::transaction(function () use ($policy, $companyId, $steps): void {
            Company::query()
                ->whereKey($companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPolicy = LeaveApprovalPolicy::query()
                ->whereKey($policy->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = LeaveApprovalPolicyStep::query()
                ->where('company_id', $companyId)
                ->where('leave_approval_policy_id', $lockedPolicy->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (LeaveApprovalPolicyStep $step): int => (int) $step->id);

            // Move existing sequences out of the unique (policy_id, sequence) range
            // so reordering by ID cannot collide mid-sync.
            $sequenceOffset = 10000;
            foreach ($existing as $existingStep) {
                $existingStep->forceFill([
                    'sequence' => $sequenceOffset + (int) $existingStep->sequence,
                ])->save();
            }

            $desired = array_values($steps);
            $submittedIds = [];

            foreach ($desired as $index => $desiredStep) {
                $stepId = isset($desiredStep['id']) && $desiredStep['id'] !== null && $desiredStep['id'] !== ''
                    ? (int) $desiredStep['id']
                    : null;

                $payload = [
                    'company_id' => $companyId,
                    'leave_approval_policy_id' => $lockedPolicy->id,
                    'sequence' => $index + 1,
                    'approver_type' => $desiredStep['approver_type'],
                    'approver_employee_id' => $desiredStep['approver_employee_id'] ?? null,
                    'is_required' => array_key_exists('is_required', $desiredStep)
                        ? (bool) $desiredStep['is_required']
                        : true,
                ];

                if ($stepId === null) {
                    $row = new LeaveApprovalPolicyStep;
                    $row->forceFill($payload)->save();

                    continue;
                }

                if (isset($submittedIds[$stepId])) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.id" => 'Duplicate approval step IDs are not allowed.',
                    ]);
                }
                $submittedIds[$stepId] = true;

                $existingStep = $existing->get($stepId);

                if ($existingStep === null) {
                    throw ValidationException::withMessages([
                        "steps.{$index}.id" => 'The selected approval step is invalid for this policy.',
                    ]);
                }

                $existingStep->forceFill($payload)->save();
                $existing->forget($stepId);
            }

            foreach ($existing as $orphan) {
                $orphan->delete();
            }
        });
    }
}
