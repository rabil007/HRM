<?php

namespace Database\Factories;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\Client;
use App\Models\CompanyVisaType;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CrewAssignment>
 */
class CrewAssignmentFactory extends Factory
{
    protected $model = CrewAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assignment_no' => 'CA-'.Str::upper(Str::random(8)),
            'rank_id' => static function (): int {
                return Rank::query()->create([
                    'name' => 'R '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'client_id' => static function (): int {
                return Client::query()->create([
                    'name' => 'C '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'vessel_id' => static function (): int {
                return Vessel::query()->create([
                    'name' => fake()->unique()->words(2, true).' Vessel',
                    'vessel_type_id' => VesselType::query()->create([
                        'name' => 'VT '.Str::uuid()->toString(),
                        'is_active' => true,
                    ])->id,
                    'is_active' => true,
                ])->id;
            },
            'company_visa_type_id' => static function (): int {
                return CompanyVisaType::query()->create([
                    'name' => 'CVT '.Str::uuid()->toString(),
                    'is_active' => true,
                ])->id;
            },
            'status' => CrewAssignmentStatus::Draft,
            'current_phase_id' => null,
            'planned_join_at' => null,
            'planned_signoff_at' => null,
            'planned_travel_at' => null,
            'started_at' => null,
            'closed_at' => null,
            'previous_assignment_id' => null,
            'employee_deployment_id' => null,
            'crew_planning_assignment_id' => null,
            'source' => 'manual',
            'remarks' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(fn () => [
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'rank_id' => $employee->rank_id,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => CrewAssignmentStatus::Draft,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => CrewAssignmentStatus::Active,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => CrewAssignmentStatus::Completed,
            'started_at' => now()->subMonths(3),
            'closed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => CrewAssignmentStatus::Cancelled,
            'closed_at' => now(),
        ]);
    }

    public function onVessel(): static
    {
        return $this->active()->afterCreating(function (CrewAssignment $assignment): void {
            $phase = CrewAssignmentPhase::factory()
                ->forAssignment($assignment)
                ->onVessel()
                ->create(['sequence' => 1]);

            $assignment->update([
                'current_phase_id' => $phase->id,
            ]);
        });
    }

    public function joinStandby(): static
    {
        return $this->active()->afterCreating(function (CrewAssignment $assignment): void {
            $phase = CrewAssignmentPhase::factory()
                ->forAssignment($assignment)
                ->state([
                    'phase_code' => CrewPhaseCode::JoinStandby,
                    'sequence' => 1,
                ])
                ->active()
                ->create();

            $assignment->update([
                'current_phase_id' => $phase->id,
            ]);
        });
    }

    public function training(): static
    {
        return $this->active()->afterCreating(function (CrewAssignment $assignment): void {
            $phase = CrewAssignmentPhase::factory()
                ->forAssignment($assignment)
                ->training()
                ->create(['sequence' => 1]);

            $assignment->update([
                'current_phase_id' => $phase->id,
            ]);
        });
    }

    public function home(): static
    {
        return $this->completed()->afterCreating(function (CrewAssignment $assignment): void {
            $phase = CrewAssignmentPhase::factory()
                ->forAssignment($assignment)
                ->home()
                ->create(['sequence' => 1]);

            $assignment->update([
                'current_phase_id' => $phase->id,
            ]);
        });
    }
}
