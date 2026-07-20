<?php

namespace Database\Factories;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewTimesheetPreparation>
 */
class CrewTimesheetPreparationFactory extends Factory
{
    protected $model = CrewTimesheetPreparation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => static function (): int {
                throw new \InvalidArgumentException('company_id must be set via forPeriod()');
            },
            'payroll_period_id' => static function (): int {
                throw new \InvalidArgumentException('payroll_period_id must be set via forPeriod()');
            },
            'version' => 1,
            'status' => CrewTimesheetPreparationStatus::Draft,
            'cutoff_date' => null,
            'source_hash' => null,
            'prepared_by' => User::factory(),
            'prepared_at' => now(),
            'submitted_by' => null,
            'submitted_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'applied_by' => null,
            'applied_at' => null,
            'decision_notes' => null,
        ];
    }

    public function forPeriod(PayrollPeriod $period): static
    {
        return $this->state(fn () => [
            'company_id' => $period->company_id,
            'payroll_period_id' => $period->id,
        ]);
    }

    public function version(int $version): static
    {
        return $this->state(fn () => [
            'version' => $version,
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => CrewTimesheetPreparationStatus::Submitted,
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => CrewTimesheetPreparationStatus::Approved,
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function applied(): static
    {
        return $this->state(fn () => [
            'status' => CrewTimesheetPreparationStatus::Applied,
            'applied_by' => User::factory(),
            'applied_at' => now(),
        ]);
    }
}
