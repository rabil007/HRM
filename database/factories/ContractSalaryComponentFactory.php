<?php

namespace Database\Factories;

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\EmployeeContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractSalaryComponent>
 */
class ContractSalaryComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = SalaryComponentCode::Basic;

        return [
            'contract_id' => fn () => EmployeeContract::factory()->create()->id,
            'company_id' => fn (array $attributes) => EmployeeContract::query()
                ->whereKey($attributes['contract_id'])
                ->value('company_id'),
            'component_code' => $code,
            'component_name' => $code->label(),
            'rate_type' => $code->defaultRateType(),
            'amount' => $this->faker->randomFloat(2, 1000, 10000),
            'status' => SalaryComponentStatus::Active,
        ];
    }

    public function code(SalaryComponentCode $code): static
    {
        return $this->state(fn () => [
            'component_code' => $code,
            'component_name' => $code->label(),
            'rate_type' => $code->defaultRateType(),
        ]);
    }
}
