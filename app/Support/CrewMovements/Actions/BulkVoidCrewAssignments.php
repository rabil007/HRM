<?php

namespace App\Support\CrewMovements\Actions;

use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewAssignmentVoidGuard;
use App\Support\EmployeeTrainings\StoresEmployeeTrainingCertificate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class BulkVoidCrewAssignments
{
    public function __construct(
        private readonly CrewAssignmentVoidGuard $guard,
        private readonly StoresEmployeeTrainingCertificate $certificateStore,
    ) {}

    /**
     * @param  list<int>  $assignmentIds
     * @return Collection<int, CrewAssignment>
     */
    public function handle(
        int $companyId,
        array $assignmentIds,
        User $actor,
        string $reason,
        bool $deleteSeaService = false,
        bool $deleteTraining = false,
    ): Collection {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'void_reason' => 'A void reason is required.',
            ]);
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($companyId);

        try {
            if ($deleteSeaService) {
                abort_unless(
                    $actor->can('sea_services.delete'),
                    403,
                    'Unauthorized to delete sea service records.',
                );
            }

            if ($deleteTraining) {
                abort_unless(
                    $actor->can('training.delete'),
                    403,
                    'Unauthorized to delete training records.',
                );
            }

            $uniqueIds = array_values(array_unique(array_map('intval', $assignmentIds)));

            if ($uniqueIds === []) {
                throw ValidationException::withMessages([
                    'assignment_ids' => 'At least one crew assignment must be selected.',
                ]);
            }

            return DB::transaction(function () use (
                $companyId,
                $uniqueIds,
                $actor,
                $reason,
                $deleteSeaService,
                $deleteTraining
            ): Collection {
                $assignments = CrewAssignmentAccess::queryForCompany($companyId, $actor)
                    ->withTrashed()
                    ->whereIn('crew_assignments.id', $uniqueIds)
                    ->orderBy('crew_assignments.id')
                    ->lockForUpdate()
                    ->with(['currentPhase'])
                    ->get();

                if ($assignments->count() !== count($uniqueIds)) {
                    abort(404, 'One or more selected assignments could not be found in the active company.');
                }

                // Preflight safety checks for all assignments in a single batched check
                $this->guard->assertCanVoidMany($assignments, $companyId, ignoreLinkedSeaService: $deleteSeaService);

                $phases = CrewAssignmentPhase::query()
                    ->where('company_id', $companyId)
                    ->whereIn('crew_assignment_id', $uniqueIds)
                    ->get(['id', 'crew_assignment_id']);

                $phaseIds = $phases->pluck('id')->all();
                $phaseToAssignment = [];
                foreach ($phases as $phase) {
                    $phaseToAssignment[(int) $phase->id] = (int) $phase->crew_assignment_id;
                }

                $seaServiceDeletedCounts = [];
                if ($deleteSeaService && $phaseIds !== []) {
                    $seaServices = EmployeeSeaService::query()
                        ->where('company_id', $companyId)
                        ->whereIn('crew_assignment_phase_id', $phaseIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    foreach ($seaServices as $seaService) {
                        $aId = $phaseToAssignment[(int) $seaService->crew_assignment_phase_id] ?? null;
                        if ($aId !== null) {
                            $seaServiceDeletedCounts[$aId] = ($seaServiceDeletedCounts[$aId] ?? 0) + 1;
                        }
                        $seaService->delete();
                    }
                }

                $trainingDeletedCounts = [];
                $certificatePathsToDelete = [];
                if ($deleteTraining && $phaseIds !== []) {
                    $trainings = EmployeeTraining::query()
                        ->where('company_id', $companyId)
                        ->whereIn('source_crew_assignment_phase_id', $phaseIds)
                        ->with('versions')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    foreach ($trainings as $training) {
                        $aId = $phaseToAssignment[(int) $training->source_crew_assignment_phase_id] ?? null;
                        if ($aId !== null) {
                            $trainingDeletedCounts[$aId] = ($trainingDeletedCounts[$aId] ?? 0) + 1;
                        }

                        $paths = $this->certificateStore->resolveCertificatePaths($training);
                        if ($paths !== []) {
                            $certificatePathsToDelete = array_merge($certificatePathsToDelete, $paths);
                        }

                        $training->delete();
                    }
                }

                if ($certificatePathsToDelete !== []) {
                    $pathsToClean = array_values(array_unique($certificatePathsToDelete));
                    DB::afterCommit(function () use ($pathsToClean, $companyId): void {
                        $this->certificateStore->deletePaths($pathsToClean, $companyId);
                    });
                }

                $isBulk = $assignments->count() > 1;

                foreach ($assignments as $assignment) {
                    $previousStatus = $assignment->status?->value;
                    $previousPhase = $assignment->currentPhase?->phase_code?->value;

                    $assignment->forceFill([
                        'voided_at' => now(),
                        'voided_by' => $actor->id,
                        'void_reason' => $reason,
                        'updated_by' => $actor->id,
                    ])->save();

                    $this->softDeleteDerivedPlanning($assignment, $companyId);

                    $assignment->delete();

                    $this->logVoid(
                        assignment: $assignment,
                        companyId: $companyId,
                        actor: $actor,
                        reason: $reason,
                        previousStatus: $previousStatus,
                        previousPhase: $previousPhase,
                        isBulk: $isBulk,
                        deleteSeaService: $deleteSeaService,
                        deleteTraining: $deleteTraining,
                        seaServiceRecordsDeleted: $seaServiceDeletedCounts[$assignment->id] ?? 0,
                        trainingRecordsDeleted: $trainingDeletedCounts[$assignment->id] ?? 0,
                    );
                }

                return $assignments;
            });
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    private function softDeleteDerivedPlanning(CrewAssignment $assignment, int $companyId): void
    {
        $linked = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->where('crew_assignment_id', $assignment->id)
            ->lockForUpdate()
            ->first();

        if ($linked === null || $linked->trashed()) {
            return;
        }

        $linked->delete();
    }

    private function logVoid(
        CrewAssignment $assignment,
        int $companyId,
        User $actor,
        string $reason,
        ?string $previousStatus,
        ?string $previousPhase,
        bool $isBulk,
        bool $deleteSeaService,
        bool $deleteTraining,
        int $seaServiceRecordsDeleted,
        int $trainingRecordsDeleted,
    ): void {
        $activity = activity()
            ->performedOn($assignment)
            ->causedBy($actor)
            ->event('crew_assignment_voided')
            ->withProperties([
                'event' => 'crew_assignment_voided',
                'company_id' => $companyId,
                'crew_assignment_id' => (int) $assignment->id,
                'assignment_no' => $assignment->assignment_no,
                'actor_user_id' => (int) $actor->id,
                'void_reason' => $reason,
                'reason' => $reason,
                'previous_status' => $previousStatus,
                'previous_phase_code' => $previousPhase,
                'is_bulk' => $isBulk,
                'delete_sea_service' => $deleteSeaService,
                'delete_training' => $deleteTraining,
                'sea_service_records_deleted' => $seaServiceRecordsDeleted,
                'training_records_deleted' => $trainingRecordsDeleted,
                'timestamp' => now()->toIso8601String(),
            ])
            ->log('Crew assignment voided as erroneous');

        $activity->forceFill(['company_id' => $companyId])->save();
    }
}
