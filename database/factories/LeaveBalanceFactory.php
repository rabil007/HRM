<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveBalance>
 */
class LeaveBalanceFactory extends Factory
{
    protected $model = LeaveBalance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'year' => (int) now()->year,
            'entitled_days' => 30,
            'used_days' => 0,
            'pending_days' => 0,
            'carried_days' => 0,
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn (): array => [
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
        ]);
    }

    public function forLeaveType(LeaveType $leaveType): static
    {
        return $this->state(fn (): array => [
            'company_id' => $leaveType->company_id,
            'leave_type_id' => $leaveType->id,
            'entitled_days' => $leaveType->days_per_year,
        ]);
    }
}
