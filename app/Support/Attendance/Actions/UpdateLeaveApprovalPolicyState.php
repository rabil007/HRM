<?php

namespace App\Support\Attendance\Actions;

use App\Models\Company;
use App\Models\LeaveApprovalPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Centralises company-scoped leave approval policy `is_default` / `status` transitions.
 */
final class UpdateLeaveApprovalPolicyState
{
    private const ONLY_DEFAULT_UNSET_MESSAGE = 'Select another policy as the company default before removing this default.';

    private const INACTIVE_DEFAULT_MESSAGE = 'The company default leave approval policy must remain active.';

    /**
     * Apply intended is_default / status changes under a company row lock.
     *
     * @param  array{is_default?: bool, status?: string|null, name?: string|null, description?: string|null, updated_by?: int|null}  $attributes
     */
    public function handle(LeaveApprovalPolicy $policy, int $companyId, array $attributes): LeaveApprovalPolicy
    {
        return DB::transaction(function () use ($policy, $companyId, $attributes): LeaveApprovalPolicy {
            Company::query()
                ->whereKey($companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = LeaveApprovalPolicy::query()
                ->whereKey($policy->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $intendedStatus = array_key_exists('status', $attributes)
                ? (string) ($attributes['status'] ?? $locked->status)
                : (string) $locked->status;

            $intendedDefault = array_key_exists('is_default', $attributes)
                ? (bool) $attributes['is_default']
                : (bool) $locked->is_default;

            if ($intendedDefault && $intendedStatus === 'inactive') {
                throw ValidationException::withMessages([
                    'status' => self::INACTIVE_DEFAULT_MESSAGE,
                ]);
            }

            if ((bool) $locked->is_default && ! $intendedDefault) {
                throw ValidationException::withMessages([
                    'is_default' => self::ONLY_DEFAULT_UNSET_MESSAGE,
                ]);
            }

            if ((bool) $locked->is_default && $intendedStatus === 'inactive') {
                throw ValidationException::withMessages([
                    'status' => self::INACTIVE_DEFAULT_MESSAGE,
                ]);
            }

            $fill = [];

            if (array_key_exists('name', $attributes)) {
                $fill['name'] = $attributes['name'];
            }

            if (array_key_exists('description', $attributes)) {
                $fill['description'] = $attributes['description'];
            }

            if (array_key_exists('status', $attributes)) {
                $fill['status'] = $intendedStatus;
            }

            if (array_key_exists('updated_by', $attributes)) {
                $fill['updated_by'] = $attributes['updated_by'];
            }

            if ($fill !== []) {
                $locked->fill($fill)->save();
            }

            if ($intendedDefault && ! $locked->is_default) {
                if ($locked->steps()->count() === 0) {
                    throw ValidationException::withMessages([
                        'policy' => 'A leave approval policy must have at least one step before it can be the company default.',
                    ]);
                }

                $this->markAsDefaultWithinLock($locked, $companyId);
            }

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Dedicated set-default operation: activates the policy and clears other company defaults.
     */
    public function markAsDefault(LeaveApprovalPolicy $policy, int $companyId, ?int $updatedBy = null): LeaveApprovalPolicy
    {
        return DB::transaction(function () use ($policy, $companyId, $updatedBy): LeaveApprovalPolicy {
            Company::query()
                ->whereKey($companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = LeaveApprovalPolicy::query()
                ->whereKey($policy->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->steps()->count() === 0) {
                throw ValidationException::withMessages([
                    'policy' => 'A leave approval policy must have at least one step before it can be the company default.',
                ]);
            }

            $this->markAsDefaultWithinLock($locked, $companyId);

            if ($updatedBy !== null) {
                $locked->forceFill(['updated_by' => $updatedBy])->save();
            }

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Status-only transition (activate / deactivate).
     */
    public function updateStatus(LeaveApprovalPolicy $policy, int $companyId, string $status, ?int $updatedBy = null): LeaveApprovalPolicy
    {
        return $this->handle($policy, $companyId, [
            'status' => $status,
            'updated_by' => $updatedBy,
        ]);
    }

    private function markAsDefaultWithinLock(LeaveApprovalPolicy $policy, int $companyId): void
    {
        LeaveApprovalPolicy::query()
            ->where('company_id', $companyId)
            ->whereKeyNot($policy->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $policy->forceFill([
            'is_default' => true,
            'status' => 'active',
        ])->save();
    }
}
