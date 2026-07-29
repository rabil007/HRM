<?php

namespace App\Support\Attendance\Actions;

use App\Models\Company;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveApprovalPolicyStep;
use Illuminate\Support\Facades\DB;

/**
 * Diff-based policy step synchronization under company + policy locks.
 */
final class SyncLeaveApprovalPolicySteps
{
    /**
     * @param  list<array{approver_type: string, approver_employee_id?: int|null, is_required?: bool}>  $steps
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
                ->values();

            $desired = array_values($steps);
            $max = max($existing->count(), count($desired));

            for ($index = 0; $index < $max; $index++) {
                $desiredStep = $desired[$index] ?? null;
                $existingStep = $existing->get($index);

                if ($desiredStep === null) {
                    $existingStep?->delete();

                    continue;
                }

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

                if ($existingStep !== null) {
                    $existingStep->forceFill($payload)->save();

                    continue;
                }

                $row = new LeaveApprovalPolicyStep;
                $row->forceFill($payload)->save();
            }
        });
    }
}
