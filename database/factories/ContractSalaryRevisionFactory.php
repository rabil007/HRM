<?php

namespace Database\Factories;

use App\Models\ContractSalaryRevision;
use App\Models\EmployeeContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractSalaryRevision>
 */
class ContractSalaryRevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => fn () => EmployeeContract::factory()->create()->id,
            'company_id' => fn (array $attributes) => EmployeeContract::query()
                ->whereKey($attributes['contract_id'])
                ->value('company_id'),
            'employee_id' => fn (array $attributes) => EmployeeContract::query()
                ->whereKey($attributes['contract_id'])
                ->value('employee_id'),
            'version' => 1,
            'effective_from' => now()->toDateString(),
            'reason' => null,
            'created_by' => null,
        ];
    }
}
