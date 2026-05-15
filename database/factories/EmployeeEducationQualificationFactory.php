<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeEducationQualification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeEducationQualification>
 */
class EmployeeEducationQualificationFactory extends Factory
{
    protected $model = EmployeeEducationQualification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'certificate' => fake()->sentence(4),
            'issue_date' => fake()->optional()->date(),
            'university' => fake()->optional()->company(),
            'country_id' => null,
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn () => [
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
        ]);
    }
}
