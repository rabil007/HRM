<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SalaryInputType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryInputType>
 */
class SalaryInputTypeFactory extends Factory
{
    protected $model = SalaryInputType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->words(2, true),
            'code' => fake()->unique()->lexify('type_???'),
            'is_addition' => fake()->boolean(),
            'status' => 'active',
            'sort_order' => 0,
        ];
    }

    public function addition(): static
    {
        return $this->state(fn () => ['is_addition' => true]);
    }

    public function deduction(): static
    {
        return $this->state(fn () => ['is_addition' => false]);
    }
}
