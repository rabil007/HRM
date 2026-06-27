<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SalaryInput;
use App\Models\SalaryInputType;
use App\Support\Payroll\ProvisionDefaultSalaryInputTypes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryInput>
 */
class SalaryInputFactory extends Factory
{
    protected $model = SalaryInput::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'period_id' => PayrollPeriod::factory()->office(),
            'salary_input_type_id' => SalaryInputType::factory(),
            'amount' => fake()->randomFloat(2, 50, 500),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SalaryInput $salaryInput): void {
            if ($salaryInput->company_id === null && $salaryInput->employee_id !== null) {
                $employee = Employee::query()->find($salaryInput->employee_id);
                $salaryInput->company_id = $employee?->company_id;
            }

            if ($salaryInput->company_id === null) {
                return;
            }

            app(ProvisionDefaultSalaryInputTypes::class)->handle((int) $salaryInput->company_id);

            $typeCompanyId = SalaryInputType::query()
                ->where('id', $salaryInput->salary_input_type_id)
                ->value('company_id');

            if ($typeCompanyId !== $salaryInput->company_id) {
                $salaryInput->salary_input_type_id = SalaryInputType::query()
                    ->where('company_id', $salaryInput->company_id)
                    ->where('code', 'bonus')
                    ->value('id');
            }
        });
    }
}
