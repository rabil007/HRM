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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class BulkVoidCrewAssignments
{
    public function __construct(
        private readonly CrewAssignmentVoidGuard $guard,
        private readonly StoresEmployeeTrainingCertificate $certificateStore,
        private readonly CleanupDraftTimesheetForVoid $cleanupDraftTimesheet,
        private readonly CleanupAccommodationForVoid $cleanupAccommodation,
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
        bool $deleteDraftTimesheet = false,
        bool $deleteAccommodation = false,
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

            if ($deleteDraftTimesheet || $deleteAccommodation) {
                abort_unless(
                    $actor->can('crew_operations.assignments.void'),
                    403,
                    'Unauthorized to clean up linked void dependencies.',
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
                $deleteTraining,
                $deleteDraftTimesheet,
                $deleteAccommodation,
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
                $this->guard->assertCanVoidMany(
                    $assignments,
                    $companyId,
                    ignoreLinkedSeaService: $deleteSeaService,
                    ignoreDraftTimesheet: $deleteDraftTimesheet,
                    ignoreAccommodation: $deleteAccommodation,
                );

                $draftCleanup = [
                    'segments_deleted' => 0,
                    'preparation_lines_deleted' => 0,
                    'timesheets_recalculated' => 0,
                    'period_ids' => [],
                    'counts_by_assignment' => [],
                ];
                if ($deleteDraftTimesheet) {
                    $draftCleanup = $this->cleanupDraftTimesheet->handle($companyId, $uniqueIds);
                }

                $accommodationCleanup = [
                    'records_deleted' => 0,
                    'snapshots_by_assignment' => [],
                    'counts_by_assignment' => [],
                ];
                if ($deleteAccommodation) {
                    $accommodationCleanup = $this->cleanupAccommodation->handle($companyId, $uniqueIds);
                }

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
                        try {
                            $this->certificateStore->deletePaths($pathsToClean, $companyId);
                        } catch (Throwable $exception) {
                            Log::warning('Bulk void post-commit certificate cleanup failed.', [
                                'company_id' => $companyId,
                                'error' => $exception->getMessage(),
                            ]);
                            report($exception);
                        }
                    });
                }

                $isBulk = $assignments->count() > 1;

                foreach ($assignments as $assignment) {
                    $previousStatus = $assignment->status?->value;
                    $previousPhase = $assignment->currentPhase?->phase_code?->value;
                    $assignmentId = (int) $assignment->id;

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
                        deleteDraftTimesheet: $deleteDraftTimesheet,
                        deleteAccommodation: $deleteAccommodation,
                        seaServiceRecordsDeleted: $seaServiceDeletedCounts[$assignmentId] ?? 0,
                        trainingRecordsDeleted: $trainingDeletedCounts[$assignmentId] ?? 0,
                        draftTimesheetSegmentsDeleted: (int) ($draftCleanup['counts_by_assignment'][$assignmentId]['segments_deleted'] ?? 0),
                        draftTimesheetPrepLinesDeleted: (int) ($draftCleanup['counts_by_assignment'][$assignmentId]['preparation_lines_deleted'] ?? 0),
                        accommodationRecordsDeleted: (int) ($accommodationCleanup['counts_by_assignment'][$assignmentId] ?? 0),
                        accommodationSnapshots: $accommodationCleanup['snapshots_by_assignment'][$assignmentId] ?? [],
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

    /**
     * @param  list<array<string, mixed>>  $accommodationSnapshots
     */
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
        bool $deleteDraftTimesheet,
        bool $deleteAccommodation,
        int $seaServiceRecordsDeleted,
        int $trainingRecordsDeleted,
        int $draftTimesheetSegmentsDeleted,
        int $draftTimesheetPrepLinesDeleted,
        int $accommodationRecordsDeleted,
        array $accommodationSnapshots,
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
                'delete_draft_timesheet' => $deleteDraftTimesheet,
                'delete_accommodation' => $deleteAccommodation,
                'sea_service_records_deleted' => $seaServiceRecordsDeleted,
                'training_records_deleted' => $trainingRecordsDeleted,
                'draft_timesheet_segments_deleted' => $draftTimesheetSegmentsDeleted,
                'draft_timesheet_prep_lines_deleted' => $draftTimesheetPrepLinesDeleted,
                'accommodation_records_deleted' => $accommodationRecordsDeleted,
                'accommodation_deleted_snapshot' => $accommodationSnapshots,
                'timestamp' => now()->toIso8601String(),
            ])
            ->log('Crew assignment voided as erroneous');

        $activity->forceFill(['company_id' => $companyId])->save();
    }
}
