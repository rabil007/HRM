<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeContract>
 */
class EmployeeContractFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-2 years', 'now');

        return [
            'employee_id' => fn () => Employee::factory()->create()->id,
            'company_id' => fn (array $attributes) => Employee::query()->whereKey($attributes['employee_id'])->value('company_id'),
            'contract_type' => $this->faker->randomElement(['limited', 'unlimited', 'part_time', 'contract']),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $this->faker->optional()->dateTimeBetween($start, '+2 years')?->format('Y-m-d'),
            'probation_end_date' => $this->faker->optional()->dateTimeBetween($start, '+6 months')?->format('Y-m-d'),
            'labor_contract_id' => $this->faker->optional()->bothify('LCID-########'),
            'status' => 'active',
        ];
    }
}
