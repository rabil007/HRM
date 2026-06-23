<?php

namespace Database\Factories;

use App\Enums\SalaryAdjustmentStatus;
use App\Enums\SalaryAdjustmentType;
use App\Models\Employee;
use App\Models\SalaryAdjustment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryAdjustment>
 */
class SalaryAdjustmentFactory extends Factory
{
    protected $model = SalaryAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => static function (): int {
                throw new \InvalidArgumentException('company_id must be set via for()');
            },
            'employee_id' => Employee::factory(),
            'period_id' => null,
            'type' => fake()->randomElement(SalaryAdjustmentType::cases()),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'reason' => fake()->sentence(),
            'status' => SalaryAdjustmentStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => SalaryAdjustmentStatus::Approved,
            'approved_at' => now(),
        ]);
    }
}
