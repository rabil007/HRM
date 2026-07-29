<?php

namespace App\Support\Attendance\Data;

use App\Enums\LeaveApprovalApproverType;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicyStep;
use App\Models\User;

final class ResolvedLeaveApprovalStep
{
    public function __construct(
        public readonly int $sequence,
        public readonly LeaveApprovalApproverType $approverType,
        public readonly Employee $approverEmployee,
        public readonly User $approverUser,
        public readonly bool $isRequired,
        public readonly ?Department $sourceDepartment = null,
        public readonly ?LeaveApprovalPolicyStep $policyStep = null,
    ) {}

    /**
     * @return array{
     *     sequence: int,
     *     approver_type: string,
     *     approver_employee_id: int,
     *     approver_user_id: int,
     *     source_department_id: int|null,
     *     policy_step_id: int|null,
     *     policy_id: int|null,
     *     policy_name: string|null,
     *     policy_step_label: string|null,
     *     is_required: bool,
     * }
     */
    public function toPersistenceArray(
        int $companyId,
        int $leaveRequestId,
        ?int $policyId = null,
        ?string $policyName = null,
    ): array {
        $stepLabel = $this->policyStep !== null
            ? sprintf('Step %d: %s', $this->sequence, $this->approverType->label())
            : $this->approverType->label();

        return [
            'company_id' => $companyId,
            'leave_request_id' => $leaveRequestId,
            'sequence' => $this->sequence,
            'approver_type' => $this->approverType->value,
            'approver_employee_id' => (int) $this->approverEmployee->id,
            'approver_user_id' => (int) $this->approverUser->id,
            'source_department_id' => $this->sourceDepartment?->id !== null
                ? (int) $this->sourceDepartment->id
                : null,
            'policy_step_id' => $this->policyStep?->id !== null
                ? (int) $this->policyStep->id
                : null,
            'policy_id' => $policyId,
            'policy_name' => $policyName,
            'policy_step_label' => $stepLabel,
            'is_required' => $this->isRequired,
        ];
    }
}
