<?php

namespace Database\Factories;

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewMovementCorrection>
 */
class CrewMovementCorrectionFactory extends Factory
{
    protected $model = CrewMovementCorrection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 1,
            'crew_assignment_id' => 1,
            'crew_assignment_phase_id' => null,
            'status' => CrewMovementCorrectionStatus::Pending,
            'original_values' => [
                'actual_start_at' => [
                    'value' => now()->subDays(10)->toIso8601String(),
                    'display' => now()->subDays(10)->toDateTimeString(),
                ],
            ],
            'proposed_values' => [
                'actual_start_at' => [
                    'value' => now()->subDays(9)->toIso8601String(),
                    'display' => now()->subDays(9)->toDateTimeString(),
                ],
            ],
            'applied_values' => null,
            'reason' => 'Correct join date after vessel log review.',
            'decision_notes' => null,
            'requested_by' => User::factory(),
            'decided_by' => null,
            'requested_at' => now(),
            'decided_at' => null,
        ];
    }

    public function forAssignment(CrewAssignment $assignment, ?CrewAssignmentPhase $phase = null): static
    {
        return $this->state(fn () => [
            'company_id' => $assignment->company_id,
            'crew_assignment_id' => $assignment->id,
            'crew_assignment_phase_id' => $phase?->id,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => CrewMovementCorrectionStatus::Pending,
            'decided_by' => null,
            'decided_at' => null,
            'decision_notes' => null,
            'applied_values' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => CrewMovementCorrectionStatus::Approved,
            'decided_at' => now(),
            'applied_values' => [
                'actual_start_at' => [
                    'value' => now()->subDays(9)->toIso8601String(),
                    'display' => now()->subDays(9)->toDateTimeString(),
                ],
            ],
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => CrewMovementCorrectionStatus::Rejected,
            'decided_at' => now(),
            'decision_notes' => 'Insufficient evidence.',
            'applied_values' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => CrewMovementCorrectionStatus::Cancelled,
            'decided_at' => now(),
            'decision_notes' => 'Cancelled by requester.',
            'applied_values' => null,
        ]);
    }
}
