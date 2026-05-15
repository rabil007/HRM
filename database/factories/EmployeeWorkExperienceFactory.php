<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeWorkExperience;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeWorkExperience>
 */
class EmployeeWorkExperienceFactory extends Factory
{
    protected $model = EmployeeWorkExperience::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort_order' => 0,
            'company_name' => fake()->company(),
            'job_title' => fake()->jobTitle(),
            'date_from' => fake()->date(),
            'date_to' => fake()->optional()->date(),
            'responsibility' => fake()->optional()->sentence(),
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
