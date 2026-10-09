<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Enums\CrewMovementAction;
use App\Enums\CrewScheduledMovementErrorCode;
use App\Enums\CrewScheduledMovementStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewScheduledMovement;
use App\Models\User;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScheduleCrewMovement
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(
        int $companyId,
        CrewAssignment $assignment,
        CrewMovementAction $action,
        array $validated,
        User $actor,
    ): CrewScheduledMovement {
        if (! CrewSchedulableMovementActions::isSchedulable($action)) {
            throw CrewMovementException::make(
                'This movement action cannot be scheduled for later.',
                CrewScheduledMovementErrorCode::NotSchedulable->value,
            );
        }

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $scheduledAtLocal = (string) ($validated['scheduled_at'] ?? '');
        $scheduledAtUtc = CrewScheduledMovementTimestamp::fromCompanyLocal($scheduledAtLocal, $timezone);

        if ($scheduledAtUtc->lessThanOrEqualTo(CrewScheduledMovementTimestamp::nowUtc())) {
            throw CrewMovementException::make(
                'Schedule for Later requires a future date and time in the company timezone. Use Record Now for current or historical movements.',
                CrewScheduledMovementErrorCode::ScheduledAtNotFuture->value,
            );
        }

        return DB::transaction(function () use (
            $companyId,
            $assignment,
            $action,
            $validated,
            $actor,
            $timezone,
            $scheduledAtLocal,
            $scheduledAtUtc,
        ): CrewScheduledMovement {
            $locked = CrewAssignment::query()
                ->where('company_id', $companyId)
                ->whereKey($assignment->id)
                ->lockForUpdate()
                ->with('currentPhase')
                ->first();

            if ($locked === null) {
                throw CrewMovementException::make(
                    'Crew assignment not found for this company.',
                    'assignment_not_found',
                );
            }

            $existing = CrewScheduledMovement::query()
                ->where('company_id', $companyId)
                ->where('crew_assignment_id', $locked->id)
                ->unresolved()
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'scheduled_at' => 'This assignment already has a pending scheduled movement. Edit, reschedule, or cancel it first.',
                ]);
            }

            $available = CrewMovementAvailableActions::for($locked);

            if (! in_array($action->value, $available, true)) {
                throw CrewMovementException::make(
                    sprintf('%s is not available for the current assignment state.', $action->label()),
                    CrewScheduledMovementErrorCode::ActionNoLongerEligible->value,
                );
            }

            $current = $locked->currentPhase;
            $nextPhase = isset($validated['next_phase']) ? (string) $validated['next_phase'] : null;
            $expectedResult = CrewSchedulableMovementActions::expectedResultPhase($action, $nextPhase);

            $schedule = CrewScheduledMovement::query()->create([
                'company_id' => $companyId,
                'crew_assignment_id' => $locked->id,
                'employee_id' => $locked->employee_id,
                'movement_action' => $action,
                'action_payload' => CrewScheduledMovementPayload::fromValidated($action, $validated),
                'scheduled_at' => CrewScheduledMovementTimestamp::storeUtc($scheduledAtUtc),
                'scheduled_timezone' => $timezone,
                'status' => CrewScheduledMovementStatus::Scheduled,
                'expected_current_phase_id' => $current?->id,
                'expected_current_phase_code' => $current?->phase_code?->value,
                'expected_current_phase_sequence' => $current?->sequence,
                'expected_vessel_id' => $locked->vessel_id,
                'expected_result_phase_code' => $expectedResult?->value,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            activity()
                ->performedOn($schedule)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_scheduled_movement_created',
                    'company_id' => $companyId,
                    'crew_assignment_id' => $locked->id,
                    'movement_action' => $action->value,
                    'scheduled_at' => $scheduledAtUtc->toIso8601String(),
                    'scheduled_at_local' => $scheduledAtLocal,
                    'scheduled_timezone' => $timezone,
                    'expected_current_phase_code' => $current?->phase_code?->value,
                    'expected_result_phase_code' => $expectedResult?->value,
                ])
                ->log('Scheduled crew movement created');

            return $schedule->fresh([
                'creator:id,name',
                'updater:id,name',
                'assignment.currentPhase',
            ]) ?? $schedule;
        });
    }
}
