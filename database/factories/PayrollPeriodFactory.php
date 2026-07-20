<?php

namespace Database\Factories;

use App\Enums\CrewTimesheetMode;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-1 year', 'now');
        $end = (clone $start)->modify('+1 month -1 day');

        return [
            'company_id' => static function (): int {
                throw new \InvalidArgumentException('company_id must be set via for()');
            },
            'payroll_category' => PayrollCategory::Crew,
            'crew_timesheet_mode' => CrewTimesheetMode::Manual,
            'name' => $start->format('F Y'),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'payment_date' => (clone $end)->modify('+5 days')->format('Y-m-d'),
            'status' => PayrollPeriodStatus::Draft,
            'notes' => null,
            'created_by' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => PayrollPeriodStatus::Approved,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => PayrollPeriodStatus::Paid,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => PayrollPeriodStatus::Cancelled,
        ]);
    }

    public function office(): static
    {
        return $this->state(fn () => [
            'payroll_category' => PayrollCategory::Office,
            'crew_timesheet_mode' => null,
        ]);
    }

    public function crewOperations(): static
    {
        return $this->state(fn () => [
            'payroll_category' => PayrollCategory::Crew,
            'crew_timesheet_mode' => CrewTimesheetMode::CrewOperations,
        ]);
    }

    public function manualTimesheets(): static
    {
        return $this->state(fn () => [
            'payroll_category' => PayrollCategory::Crew,
            'crew_timesheet_mode' => CrewTimesheetMode::Manual,
        ]);
    }
}
