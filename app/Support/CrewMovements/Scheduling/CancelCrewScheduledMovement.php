<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Enums\CrewScheduledMovementErrorCode;
use App\Enums\CrewScheduledMovementStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewScheduledMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CancelCrewScheduledMovement
{
    public function handle(
        int $companyId,
        CrewScheduledMovement $schedule,
        User $actor,
        ?string $reason = null,
    ): CrewScheduledMovement {
        return DB::transaction(function () use ($companyId, $schedule, $actor, $reason): CrewScheduledMovement {
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

            if (! $locked->isUnresolved()) {
                throw CrewMovementException::make(
                    'Only unresolved schedules can be cancelled.',
                    CrewScheduledMovementErrorCode::AlreadyResolved->value,
                );
            }

            if ($locked->status === CrewScheduledMovementStatus::Processing) {
                throw CrewMovementException::make(
                    'This schedule is currently being processed and cannot be cancelled.',
                    CrewScheduledMovementErrorCode::AlreadyResolved->value,
                );
            }

            $locked->update([
                'status' => CrewScheduledMovementStatus::Cancelled,
                'cancelled_at' => CrewScheduledMovementTimestamp::storeUtc(
                    CrewScheduledMovementTimestamp::nowUtc(),
                ),
                'cancelled_by' => $actor->id,
                'updated_by' => $actor->id,
                'last_error_code' => $reason !== null && $reason !== ''
                    ? CrewScheduledMovementErrorCode::Cancelled->value
                    : $locked->last_error_code,
                'last_error_message' => $reason !== null && trim($reason) !== ''
                    ? mb_substr(trim($reason), 0, 1000)
                    : $locked->last_error_message,
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'crew_scheduled_movement_cancelled',
                    'company_id' => $companyId,
                    'crew_assignment_id' => $locked->crew_assignment_id,
                    'movement_action' => $locked->movement_action->value,
                    'reason' => $reason,
                ])
                ->log('Scheduled crew movement cancelled');

            return $locked->fresh([
                'creator:id,name',
                'updater:id,name',
                'canceller:id,name',
            ]) ?? $locked;
        });
    }
}
