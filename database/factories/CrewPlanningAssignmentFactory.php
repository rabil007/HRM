<?php

namespace Database\Factories;

use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CrewPlanningAssignment>
 */
class CrewPlanningAssignmentFactory extends Factory
{
    protected $model = CrewPlanningAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $joinDate = fake()->dateTimeBetween('now', '+3 months');
        $leaveDate = fake()->dateTimeBetween($joinDate, '+6 months');

        return [
            'vessel_id' => static function (array $attributes): int {
                $companyId = $attributes['company_id'] ?? null;

                if ($companyId === null) {
                    throw new \InvalidArgumentException('company_id must be set before vessel_id on CrewPlanningAssignmentFactory.');
                }

                return Vessel::query()->create([
                    'company_id' => $companyId,
                    'name' => fake()->unique()->words(2, true).' Vessel',
                    'vessel_type_id' => VesselType::query()->create([
                        'name' => 'VT '.Str::uuid()->toString(),
                        'is_active' => true,
                    ])->id,
                    'is_active' => true,
                ])->id;
            },
            'rank_id' => static function (): int {
                return Rank::query()->create([
                    'name' => 'R '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'employee_id' => null,
            'planned_join_date' => $joinDate->format('Y-m-d'),
            'planned_leave_date' => $leaveDate->format('Y-m-d'),
            'notes' => null,
        ];
    }

    public function withEmployee(Employee $employee): static
    {
        return $this->state(fn () => [
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
        ]);
    }
}
