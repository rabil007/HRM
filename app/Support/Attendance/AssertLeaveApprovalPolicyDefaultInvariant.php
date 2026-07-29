<?php

namespace App\Support\Attendance;

use App\Models\LeaveApprovalPolicy;
use Illuminate\Validation\ValidationException;

final class AssertLeaveApprovalPolicyDefaultInvariant
{
    /**
     * @param  array{is_default?: bool, status?: string|null}  $data
     */
    public function assertForCreate(array $data): void
    {
        $isDefault = (bool) ($data['is_default'] ?? false);
        $status = (string) ($data['status'] ?? 'active');

        $this->assertDefaultRemainsActive($isDefault, $status);
    }

    /**
     * @param  array{is_default?: bool, status?: string|null}  $data
     */
    public function assertForUpdate(LeaveApprovalPolicy $policy, array $data): void
    {
        $status = (string) ($data['status'] ?? $policy->status);

        if (array_key_exists('is_default', $data)) {
            $isDefault = (bool) $data['is_default'];
        } else {
            $isDefault = (bool) $policy->is_default;
        }

        $this->assertDefaultRemainsActive($isDefault, $status);
    }

    public function assertCanDeactivate(LeaveApprovalPolicy $policy): void
    {
        if ($policy->is_default) {
            $this->rejectInactiveDefault();
        }
    }

    public function assertCanBecomeDefault(LeaveApprovalPolicy $policy): void
    {
        if ($policy->steps()->count() === 0) {
            throw ValidationException::withMessages([
                'policy' => 'A leave approval policy must have at least one step before it can be the company default.',
            ]);
        }
    }

    public function assertDefaultRemainsActive(bool $isDefault, string $status): void
    {
        if ($isDefault && $status === 'inactive') {
            $this->rejectInactiveDefault();
        }
    }

    private function rejectInactiveDefault(): never
    {
        throw ValidationException::withMessages([
            'status' => 'The company default leave approval policy must remain active.',
        ]);
    }
}
