<?php

namespace App\Support\CrewPlanning;

use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewMovements\CrewMovementService;
use Illuminate\Support\Facades\DB;

final class CreateCrewAssignmentFromPlanning
{
    public function __construct(
        private CrewMovementService $movements,
        private SyncPlanningAssignmentFromCrewAssignment $planningSync,
    ) {}

    public function handle(CrewPlanningAssignment $planning, ?int $actorId = null): CrewAssignment
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(function () use ($planning, $actorId): CrewAssignment {
                    $linkedAssignmentId = CrewPlanningAssignment::query()
                        ->whereKey($planning->id)
                        ->value('crew_assignment_id');

                    $preReadAssignmentId = $linkedAssignmentId !== null ? (int) $linkedAssignmentId : null;

                    if ($preReadAssignmentId !== null) {
                        CrewAssignment::query()
                            ->whereKey($preReadAssignmentId)
                            ->lockForUpdate()
                            ->first();
                    }

                    $lockedPlanning = CrewPlanningAssignment::query()
                        ->whereKey($planning->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedAssignmentId = $lockedPlanning->crew_assignment_id !== null ? (int) $lockedPlanning->crew_assignment_id : null;

                    if ($lockedAssignmentId !== $preReadAssignmentId) {
                        throw CrewMovementException::make(
                            'Planning assignment linkage changed concurrently. Please retry.',
                            'planning_concurrency_conflict',
                        );
                    }

                    if ($lockedPlanning->crew_assignment_id !== null) {
                        $existing = CrewAssignment::query()
                            ->where('company_id', $lockedPlanning->company_id)
                            ->whereKey($lockedPlanning->crew_assignment_id)
                            ->first();

                        if ($existing !== null) {
                            $this->planningSync->sync($existing);

                            return $existing->fresh(['phases', 'currentPhase', 'planningAssignment']) ?? $existing;
                        }
                    }

                    if ($lockedPlanning->employee_id === null) {
                        throw CrewMovementException::make(
                            'Planning assignment has no employee.',
                            'planning_missing_employee',
                        );
                    }

                    if ($lockedPlanning->vessel_id === null || $lockedPlanning->rank_id === null) {
                        throw CrewMovementException::make(
                            'Planning assignment requires vessel and rank.',
                            'planning_missing_masters',
                        );
                    }

                    if ($lockedPlanning->planned_join_date === null) {
                        throw CrewMovementException::make(
                            'Planning assignment requires a planned join date.',
                            'planning_missing_join_date',
                        );
                    }

                    $assignment = $this->movements->createDraft(
                        (int) $lockedPlanning->company_id,
                        (int) $lockedPlanning->employee_id,
                        [
                            'rank_id' => $lockedPlanning->rank_id,
                            'vessel_id' => $lockedPlanning->vessel_id,
                            'planned_join_at' => $lockedPlanning->planned_join_date->toDateString().' 00:00:00',
                            'planned_signoff_at' => $lockedPlanning->planned_leave_date !== null
                                ? $lockedPlanning->planned_leave_date->toDateString().' 00:00:00'
                                : null,
                            'source' => 'crew_planning',
                            'remarks' => $lockedPlanning->notes,
                        ],
                        $actorId,
                    );

                    $lockedPlanning->update([
                        'crew_assignment_id' => $assignment->id,
                    ]);

                    // Preserve relieves_crew_assignment_id — conversion reuses the same Planning row.
                    $this->planningSync->sync($assignment->fresh(['phases', 'employee', 'company']) ?? $assignment);

                    return $assignment->fresh(['phases', 'currentPhase', 'planningAssignment']) ?? $assignment;
                });
            } catch (CrewMovementException $exception) {
                if ($exception->errorCode === 'planning_concurrency_conflict' && $attempt < $maxAttempts) {
                    usleep(10000);

                    continue;
                }

                throw $exception;
            }
        }

        throw CrewMovementException::make(
            'Planning assignment linkage changed concurrently. Please retry.',
            'planning_concurrency_conflict',
        );
    }
}
