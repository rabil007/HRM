<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\Employees\SeaServiceDuration;
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
        $startDate = fake()->dateTimeBetween('-5 years', '-1 year');
        $endDate = fake()->dateTimeBetween($startDate, 'now');
        $start = $startDate->format('Y-m-d');
        $end = $endDate->format('Y-m-d');
        $duration = SeaServiceDuration::fromDates($start, $end);

        return [
            'sort_order' => 0,
            'vessel_type_id' => static function (): int {
                return VesselType::query()->create([
                    'name' => 'V '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'vessel_id' => static function (array $attributes): int {
                return Vessel::query()->create([
                    'name' => fake()->unique()->words(3, true),
                    'vessel_type_id' => $attributes['vessel_type_id'],
                    'grt' => fake()->optional(0.7)->randomFloat(2, 100, 50000),
                    'bhp' => fake()->optional(0.7)->numberBetween(500, 20000),
                    'is_active' => true,
                ])->id;
            },
            'rank_id' => static function (): int {
                return Rank::query()->create([
                    'name' => 'R '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'start_date' => $start,
            'end_date' => $end,
            'total_months' => $duration['months'],
            'total_days' => $duration['days'],
            'client_id' => null,
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
