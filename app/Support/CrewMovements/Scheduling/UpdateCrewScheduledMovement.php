<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Enums\CrewScheduledMovementErrorCode;
use App\Enums\CrewScheduledMovementStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewScheduledMovement;
use App\Models\User;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
            $scheduledAtLocal = (string) ($validated['scheduled_at'] ?? $locked->scheduled_at?->timezone($timezone)->format('Y-m-d H:i:s'));
            $scheduledAt = Carbon::parse($scheduledAtLocal, $timezone);

            if ($scheduledAt->lessThanOrEqualTo(Carbon::now($timezone))) {
                throw CrewMovementException::make(
                    'Reschedule requires a future date and time in the company timezone.',
                    CrewScheduledMovementErrorCode::ScheduledAtNotFuture->value,
                );
            }

            $payload = array_key_exists('action_fields', $validated)
                ? CrewScheduledMovementPayload::fromValidated(
                    $locked->movement_action,
                    array_merge(
                        ['action' => $locked->movement_action->value],
                        is_array($validated['action_fields']) ? $validated['action_fields'] : [],
                    ),
                )
                : ($locked->action_payload ?? []);

            if (array_key_exists('action_fields', $validated)) {
                $payload = CrewScheduledMovementPayload::syncAutoLinkedDates($payload, $scheduledAtLocal);
            } else {
                $payload = CrewScheduledMovementPayload::syncAutoLinkedDates($payload, $scheduledAtLocal);
            }

            $locked->update([
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'scheduled_timezone' => $timezone,
                'action_payload' => $payload,
                'status' => CrewScheduledMovementStatus::Scheduled,
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
                    'new_scheduled_at' => $scheduledAt->toIso8601String(),
                ])
                ->log('Scheduled crew movement updated');

            return $locked->fresh([
                'creator:id,name',
                'updater:id,name',
            ]) ?? $locked;
        });
    }
}
