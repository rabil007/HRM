<?php

namespace Database\Factories;

use App\Enums\SalaryComponentCode;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractSalaryRevisionLine>
 */
class ContractSalaryRevisionLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = SalaryComponentCode::Basic;

        return [
            'revision_id' => fn () => ContractSalaryRevision::factory()->create()->id,
            'company_id' => fn (array $attributes) => ContractSalaryRevision::query()
                ->whereKey($attributes['revision_id'])
                ->value('company_id'),
            'component_code' => $code,
            'component_name' => $code->label(),
            'rate_type' => $code->defaultRateType(),
            'amount' => $this->faker->randomFloat(2, 1000, 10000),
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
