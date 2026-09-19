<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\CrewMovements\CrewMovementService;
use Illuminate\Support\Facades\DB;

final class StartCrewAssignmentFromPlanning
{
    public function __construct(
        private CrewMovementService $movements,
        private SyncPlanningAssignmentFromCrewAssignment $planningSync,
        private ResolvePlanningStartHandoff $handoff,
    ) {}

    /**
     * @param  array{planned_arrival_at?: string|null, remarks?: string|null}  $operatorChoices
     * @return array{assignment: CrewAssignment, created_new: bool}
     */
    public function handle(
        CrewPlanningAssignment $planning,
        array $operatorChoices,
        User|int|null $actor = null,
    ): array {
        $actorUser = $actor instanceof User ? $actor : null;
        $actorId = $actorUser?->id ?? (is_int($actor) ? $actor : null);
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return DB::transaction(function () use ($planning, $operatorChoices, $actorUser, $actorId): array {
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

                    $companyId = (int) $lockedPlanning->company_id;

                    $linked = $this->handoff->linkedAssignment($lockedPlanning);

                    if ($linked !== null) {
                        return [
                            'assignment' => $this->resolveLinkedStart($lockedPlanning, $linked),
                            'created_new' => false,
                        ];
                    }

                    $masters = $this->handoff->authoritativeStartMasters($lockedPlanning, $companyId, $actorUser);

                    $plannedSignoffAt = $lockedPlanning->planned_leave_date !== null
                        ? $lockedPlanning->planned_leave_date->toDateString().' 00:00:00'
                        : null;

                    $assignment = $this->movements->startAssignment(
                        $companyId,
                        $masters['employee_id'],
                        [
                            'rank_id' => $masters['rank_id'],
                            'client_id' => $masters['client_id'],
                            'vessel_id' => $masters['vessel_id'],
                            'planned_arrival_at' => $operatorChoices['planned_arrival_at'] ?? null,
                            'planned_join_at' => $masters['planned_join_at'],
                            'current_stage' => CrewPhaseCode::PreMobilisation->value,
                            'remarks' => $operatorChoices['remarks'] ?? null,
                            'source' => 'crew_planning',
                        ],
                        $actorId,
                    );

                    if ($plannedSignoffAt !== null) {
                        $assignment->update([
                            'planned_signoff_at' => $plannedSignoffAt,
                            'updated_by' => $actorId,
                        ]);
                    }

                    $lockedPlanning->update([
                        'crew_assignment_id' => $assignment->id,
                    ]);

                    $this->planningSync->sync($assignment->fresh(['phases', 'employee', 'company']) ?? $assignment);

                    return [
                        'assignment' => $assignment->fresh(['phases', 'currentPhase', 'planningAssignment']) ?? $assignment,
                        'created_new' => true,
                    ];
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

    private function resolveLinkedStart(
        CrewPlanningAssignment $planning,
        CrewAssignment $linked,
    ): CrewAssignment {
        if ($linked->status === CrewAssignmentStatus::Active) {
            $this->planningSync->sync($linked);

            return $linked->fresh(['phases', 'currentPhase', 'planningAssignment']) ?? $linked;
        }

        if ($linked->status === CrewAssignmentStatus::Draft) {
            throw CrewMovementException::make(
                'This planning record is linked to a draft crew assignment. Open the linked assignment and use Start Assignment or update the draft before starting again.',
                'planning_linked_draft',
            );
        }

        throw CrewMovementException::make(
            sprintf(
                'This planning record is linked to a %s crew assignment and cannot be started again.',
                strtolower($linked->status->value),
            ),
            'planning_linked_terminal_assignment',
        );
    }
}
