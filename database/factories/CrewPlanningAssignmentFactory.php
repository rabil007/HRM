<?php

namespace Database\Factories;

use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\Position;
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
            'position_id' => static function (array $attributes): int {
                $companyId = $attributes['company_id'] ?? null;

                if ($companyId === null) {
                    throw new \InvalidArgumentException('company_id must be set before position_id on CrewPlanningAssignmentFactory.');
                }

                return Position::query()->create([
                    'company_id' => $companyId,
                    'title' => 'P '.Str::uuid()->toString(),
                    'status' => 'active',
                    'is_crew_position' => true,
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
            'position_id' => $employee->position_id,
        ]);
    }
}
