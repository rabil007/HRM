<?php

namespace Database\Factories;

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\PayrollRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRecord>
 */
class PayrollRecordFactory extends Factory
{
    protected $model = PayrollRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gross = $this->faker->randomFloat(2, 1000, 15000);
        $deductions = $this->faker->randomFloat(2, 0, 500);

        return [
            'company_id' => static function (): int {
                throw new \InvalidArgumentException('company_id must be set via for()');
            },
            'employee_id' => Employee::factory(),
            'period_id' => static function (): int {
                throw new \InvalidArgumentException('period_id must be set via for()');
            },
            'payroll_category' => PayrollCategory::Crew,
            'basic_salary' => $gross * 0.6,
            'other_allowances' => $gross * 0.2,
            'overtime_pay' => $gross * 0.1,
            'bonus' => $gross * 0.1,
            'gross_salary' => $gross,
            'other_deductions' => $deductions,
            'total_deductions' => $deductions,
            'net_salary' => $gross - $deductions,
            'status' => 'draft',
            'calculation_breakdown' => null,
        ];
    }

    public function crew(): static
    {
        return $this->state(fn () => [
            'payroll_category' => PayrollCategory::Crew,
        ]);
    }
}
