<?php

namespace Database\Factories;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewAssignmentPhase>
 */
class CrewAssignmentPhaseFactory extends Factory
{
    protected $model = CrewAssignmentPhase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phase_code' => CrewPhaseCode::PreMobilisation,
            'sequence' => 1,
            'status' => CrewPhaseStatus::Planned,
            'planned_start_at' => null,
            'planned_end_at' => null,
            'actual_start_at' => null,
            'actual_end_at' => null,
            'details' => null,
            'remarks' => null,
            'started_by' => null,
            'completed_by' => null,
        ];
    }

    public function forAssignment(CrewAssignment $assignment): static
    {
        return $this->state(fn () => [
            'company_id' => $assignment->company_id,
            'crew_assignment_id' => $assignment->id,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => CrewPhaseStatus::Active,
            'actual_start_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => CrewPhaseStatus::Completed,
            'actual_start_at' => now()->subDays(7),
            'actual_end_at' => now(),
        ]);
    }

    public function onVessel(): static
    {
        return $this->active()->state(fn () => [
            'phase_code' => CrewPhaseCode::OnVessel,
        ]);
    }

    public function training(): static
    {
        return $this->active()->state(fn () => [
            'phase_code' => CrewPhaseCode::Training,
        ]);
    }

    public function home(): static
    {
        return $this->completed()->state(fn () => [
            'phase_code' => CrewPhaseCode::HomeRedeploy,
        ]);
    }
}
