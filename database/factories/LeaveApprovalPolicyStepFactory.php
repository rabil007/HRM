<?php

namespace Database\Factories;

use App\Enums\LeaveApprovalApproverType;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveApprovalPolicyStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveApprovalPolicyStep>
 */
class LeaveApprovalPolicyStepFactory extends Factory
{
    protected $model = LeaveApprovalPolicyStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 0,
            'leave_approval_policy_id' => 0,
            'sequence' => 1,
            'approver_type' => LeaveApprovalApproverType::DepartmentManager,
            'approver_employee_id' => null,
            'is_required' => true,
        ];
    }

    public function forPolicy(LeaveApprovalPolicy $policy): static
    {
        return $this->state(fn (): array => [
            'company_id' => $policy->company_id,
            'leave_approval_policy_id' => $policy->id,
        ]);
    }

    public function departmentManager(int $sequence = 1): static
    {
        return $this->state(fn (): array => [
            'sequence' => $sequence,
            'approver_type' => LeaveApprovalApproverType::DepartmentManager,
            'approver_employee_id' => null,
        ]);
    }

    public function parentManager(int $sequence = 2): static
    {
        return $this->state(fn (): array => [
            'sequence' => $sequence,
            'approver_type' => LeaveApprovalApproverType::ParentManager,
            'approver_employee_id' => null,
        ]);
    }

    public function hrApprover(?Employee $employee = null, int $sequence = 2): static
    {
        return $this->state(fn (): array => [
            'sequence' => $sequence,
            'approver_type' => LeaveApprovalApproverType::HrApprover,
            'approver_employee_id' => $employee?->id,
        ]);
    }

    public function specificEmployee(Employee $employee, int $sequence = 1): static
    {
        return $this->state(fn (): array => [
            'sequence' => $sequence,
            'approver_type' => LeaveApprovalApproverType::SpecificEmployee,
            'approver_employee_id' => $employee->id,
        ]);
    }
}
