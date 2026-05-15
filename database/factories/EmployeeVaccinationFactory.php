<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeVaccination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeVaccination>
 */
class EmployeeVaccinationFactory extends Factory
{
    protected $model = EmployeeVaccination::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort_order' => 0,
            'vaccination_name' => fake()->randomElement(['COVID-19', 'Hepatitis B', 'Yellow Fever', 'Influenza']),
            'country_id' => null,
            'first_dose_date' => fake()->optional()->date(),
            'second_dose_date' => fake()->optional()->date(),
            'booster_dose_date' => fake()->optional()->date(),
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
