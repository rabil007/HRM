<?php

namespace Database\Factories;

use App\Enums\LeaveApprovalApproverType;
use App\Models\Company;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveApprovalPolicyStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveApprovalPolicy>
 */
class LeaveApprovalPolicyFactory extends Factory
{
    protected $model = LeaveApprovalPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 0,
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'is_default' => false,
            'status' => 'active',
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
            'status' => 'active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => 'inactive',
            'is_default' => false,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'company_id' => $company->id,
        ]);
    }

    public function withDepartmentManagerStep(): static
    {
        return $this->afterCreating(function (LeaveApprovalPolicy $policy): void {
            LeaveApprovalPolicyStep::factory()->create([
                'company_id' => $policy->company_id,
                'leave_approval_policy_id' => $policy->id,
                'sequence' => 1,
                'approver_type' => LeaveApprovalApproverType::DepartmentManager,
                'approver_employee_id' => null,
                'is_required' => true,
            ]);
        });
    }

    /**
     * @param  list<array{type: LeaveApprovalApproverType|string, employee_id?: int|null, required?: bool}>  $steps
     */
    public function withSteps(array $steps): static
    {
        return $this->afterCreating(function (LeaveApprovalPolicy $policy) use ($steps): void {
            foreach (array_values($steps) as $index => $step) {
                $type = $step['type'] instanceof LeaveApprovalApproverType
                    ? $step['type']
                    : LeaveApprovalApproverType::from((string) $step['type']);

                LeaveApprovalPolicyStep::factory()->create([
                    'company_id' => $policy->company_id,
                    'leave_approval_policy_id' => $policy->id,
                    'sequence' => $index + 1,
                    'approver_type' => $type,
                    'approver_employee_id' => $step['employee_id'] ?? null,
                    'is_required' => $step['required'] ?? true,
                ]);
            }
        });
    }

    public function createdBy(?User $user): static
    {
        return $this->state(fn (): array => [
            'created_by' => $user?->id,
            'updated_by' => $user?->id,
        ]);
    }
}
