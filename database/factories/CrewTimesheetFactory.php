<?php

namespace Database\Factories;

use App\Models\CrewTimesheet;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewTimesheet>
 */
class CrewTimesheetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn (array $attributes) => Employee::query()->whereKey($attributes['employee_id'])->value('company_id'),
            'employee_id' => Employee::factory(),
            'period_id' => static function (): int {
                throw new \InvalidArgumentException('period_id must be set explicitly');
            },
            'sign_on_standby_days' => 0,
            'onsite_days' => 0,
            'sign_off_standby_days' => 0,
            'unpaid_leave_days' => 0,
            'overtime_hours' => 0,
            'additional_amount' => 0,
            'deduction_amount' => 0,
            'remarks' => null,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn () => [
            'sign_on_standby_days' => 0,
            'onsite_days' => 0,
            'sign_off_standby_days' => 0,
            'unpaid_leave_days' => 0,
        ]);
    }
}
