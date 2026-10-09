<?php

namespace App\Support\CrewMovements\Scheduling;

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

final class UpdateCrewScheduledMovement
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function handle(
        int $companyId,
        CrewScheduledMovement $schedule,
        array $validated,
        User $actor,
    ): CrewScheduledMovement {
        return DB::transaction(function () use ($companyId, $schedule, $validated, $actor): CrewScheduledMovement {
            /** @var CrewScheduledMovement|null $locked */
            $locked = CrewScheduledMovement::query()
                ->where('company_id', $companyId)
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw CrewMovementException::make(
                    'Scheduled movement not found for this company.',
                    'schedule_not_found',
                );
            }

            if (! in_array($locked->status, [
                CrewScheduledMovementStatus::Scheduled,
                CrewScheduledMovementStatus::NeedsAttention,
            ], true)) {
                throw CrewMovementException::make(
                    'Only pending or needs-attention schedules can be edited.',
                    CrewScheduledMovementErrorCode::AlreadyResolved->value,
                );
            }

            $timezone = CompanyTimezone::forCompanyId($companyId);
            $previousScheduledAt = $locked->scheduled_at?->clone();
            $scheduledAtLocal = (string) ($validated['scheduled_at'] ?? CrewScheduledMovementTimestamp::toCompanyLocalString(
                $locked->scheduled_at ?? CrewScheduledMovementTimestamp::nowUtc(),
                $timezone,
            ));
            $scheduledAtUtc = CrewScheduledMovementTimestamp::fromCompanyLocal($scheduledAtLocal, $timezone);

            if ($scheduledAtUtc->lessThanOrEqualTo(CrewScheduledMovementTimestamp::nowUtc())) {
                throw CrewMovementException::make(
                    'Reschedule requires a future date and time in the company timezone.',
                    CrewScheduledMovementErrorCode::ScheduledAtNotFuture->value,
                );
            }

            $assignment = CrewAssignment::query()
                ->where('company_id', $companyId)
                ->whereKey($locked->crew_assignment_id)
                ->lockForUpdate()
                ->with('currentPhase')
                ->first();

            if ($assignment === null) {
                throw CrewMovementException::make(
                    'Crew assignment not found for this company.',
                    'assignment_not_found',
                );
            }

            $available = CrewMovementAvailableActions::for($assignment);

            if (! in_array($locked->movement_action->value, $available, true)) {
                throw CrewMovementException::make(
                    sprintf('%s is not available for the current assignment state.', $locked->movement_action->label()),
                    CrewScheduledMovementErrorCode::ActionNoLongerEligible->value,
                );
            }

            $otherUnresolved = CrewScheduledMovement::query()
                ->where('company_id', $companyId)
                ->where('crew_assignment_id', $locked->crew_assignment_id)
                ->whereKeyNot($locked->id)
                ->unresolved()
                ->lockForUpdate()
                ->exists();

            if ($otherUnresolved) {
                throw ValidationException::withMessages([
                    'scheduled_at' => 'This assignment already has another pending scheduled movement.',
                ]);
            }

            $payloadSource = array_key_exists('action_fields', $validated)
                ? array_merge(
                    ['action' => $locked->movement_action->value],
                    is_array($validated['action_fields']) ? $validated['action_fields'] : [],
                )
                : array_merge(
                    ['action' => $locked->movement_action->value],
                    is_array($locked->action_payload) ? $locked->action_payload : [],
                );

            $payload = CrewScheduledMovementPayload::fromValidated(
                $locked->movement_action,
                $payloadSource,
            );
            $payload = CrewScheduledMovementPayload::syncAutoLinkedDates($payload, $scheduledAtLocal);

            $current = $assignment->currentPhase;
            $nextPhase = isset($payload['next_phase']) ? (string) $payload['next_phase'] : null;
            $expectedResult = CrewSchedulableMovementActions::expectedResultPhase(
                $locked->movement_action,
                $nextPhase,
            );

            $locked->update([
                'scheduled_at' => CrewScheduledMovementTimestamp::storeUtc($scheduledAtUtc),
                'scheduled_timezone' => $timezone,
                'action_payload' => $payload,
                'status' => CrewScheduledMovementStatus::Scheduled,
                'expected_current_phase_id' => $current?->id,
                'expected_current_phase_code' => $current?->phase_code?->value,
                'expected_current_phase_sequence' => $current?->sequence,
                'expected_vessel_id' => $assignment->vessel_id,
                'expected_result_phase_code' => $expectedResult?->value,
                'updated_by' => $actor->id,
                'last_error_code' => null,
                'last_error_message' => null,
                'processing_started_at' => null,
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_scheduled_movement_updated',
                    'company_id' => $companyId,
                    'crew_assignment_id' => $locked->crew_assignment_id,
                    'movement_action' => $locked->movement_action->value,
                    'old_scheduled_at' => $previousScheduledAt?->toIso8601String(),
                    'new_scheduled_at' => $scheduledAtUtc->toIso8601String(),
                    'scheduled_timezone' => $timezone,
                ])
                ->log('Scheduled crew movement updated');

            return $locked->fresh([
                'creator:id,name',
                'updater:id,name',
            ]) ?? $locked;
        });
    }
}
