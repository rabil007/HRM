<?php

namespace Database\Factories;

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\LeaveRequestApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequestApproval>
 */
class LeaveRequestApprovalFactory extends Factory
{
    protected $model = LeaveRequestApproval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 0,
            'leave_request_id' => 0,
            'sequence' => 1,
            'approver_type' => LeaveApprovalApproverType::DepartmentManager,
            'approver_employee_id' => 0,
            'approver_user_id' => 0,
            'source_department_id' => null,
            'policy_step_id' => null,
            'status' => LeaveRequestApprovalStatus::Pending,
            'is_required' => true,
            'acted_at' => null,
            'comments' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveRequestApprovalStatus::Pending,
            'acted_at' => null,
        ]);
    }

    public function waiting(): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveRequestApprovalStatus::Waiting,
            'acted_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => LeaveRequestApprovalStatus::Approved,
            'acted_at' => now(),
        ]);
    }
}
