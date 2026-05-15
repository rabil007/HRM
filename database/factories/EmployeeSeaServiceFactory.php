<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmployeeSeaService>
 */
class EmployeeSeaServiceFactory extends Factory
{
    protected $model = EmployeeSeaService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sort_order' => 0,
            'vessel_id' => static function (): int {
                return Vessel::query()->create([
                    'name' => 'V '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'rank_id' => static function (): int {
                return Rank::query()->create([
                    'name' => 'R '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'total_months' => fake()->numberBetween(0, 24),
            'total_days' => fake()->numberBetween(0, 29),
            'grt' => null,
            'bhp' => null,
            'client_id' => null,
            'is_offshore' => fake()->boolean(40),
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
