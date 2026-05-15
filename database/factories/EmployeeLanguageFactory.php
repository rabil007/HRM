<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeLanguage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeLanguage>
 */
class EmployeeLanguageFactory extends Factory
{
    protected $model = EmployeeLanguage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort_order' => 0,
            'language_name' => fake()->randomElement(['English', 'Arabic', 'Hindi', 'Tagalog']),
            'is_spoken' => fake()->boolean(),
            'is_written' => fake()->boolean(),
            'is_understood' => fake()->boolean(),
            'is_mother_tongue' => fake()->boolean(),
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
