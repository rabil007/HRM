<?php

namespace Database\Factories;

use App\Enums\CrewMovementAction;
use App\Enums\CrewScheduledMovementStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewScheduledMovement;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrewScheduledMovement>
 */
class CrewScheduledMovementFactory extends Factory
{
    protected $model = CrewScheduledMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'crew_assignment_id' => CrewAssignment::factory(),
            'employee_id' => Employee::factory(),
            'movement_action' => CrewMovementAction::JoinVessel,
            'action_payload' => [],
            'scheduled_at' => now()->addDay(),
            'scheduled_timezone' => 'UTC',
            'status' => CrewScheduledMovementStatus::Scheduled,
            'expected_current_phase_id' => null,
            'expected_current_phase_code' => null,
            'expected_current_phase_sequence' => null,
            'expected_vessel_id' => null,
            'expected_result_phase_code' => 'p4',
            'created_by' => User::factory(),
            'updated_by' => null,
            'executed_at' => null,
            'effective_occurred_at' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'execution_attempts' => 0,
            'last_error_code' => null,
            'last_error_message' => null,
            'processing_started_at' => null,
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => CrewScheduledMovementStatus::Scheduled,
        ]);
    }

    public function needsAttention(): static
    {
        return $this->state(fn (): array => [
            'status' => CrewScheduledMovementStatus::NeedsAttention,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => CrewScheduledMovementStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function executed(): static
    {
        return $this->state(fn (): array => [
            'status' => CrewScheduledMovementStatus::Executed,
            'executed_at' => now(),
            'effective_occurred_at' => now(),
        ]);
    }
}
