<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\CrewMovements\CrewAssignmentInvariantGuard;
use App\Support\CrewMovements\SeaServiceSyncService;
use App\Support\CrewPlanning\SyncPlanningAssignmentFromCrewAssignment;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Carbon\CarbonInterface;

final class ApplyCrewMovementCorrectionPipeline
{
    private readonly ApplyCrewMovementCorrection $applier;

    private readonly RecalculateTourSignoffAfterP4StartCorrection $tourSignoffRecalc;

    private readonly CrewAssignmentInvariantGuard $invariantGuard;

    private readonly SyncPlanningAssignmentFromCrewAssignment $planningSync;

    private readonly SeaServiceSyncService $seaServiceSync;

    private readonly CrewMovementCorrectionValueSnapshot $snapshot;

    public function __construct(
        ?ApplyCrewMovementCorrection $applier = null,
        ?RecalculateTourSignoffAfterP4StartCorrection $tourSignoffRecalc = null,
        ?CrewAssignmentInvariantGuard $invariantGuard = null,
        ?SyncPlanningAssignmentFromCrewAssignment $planningSync = null,
        ?SeaServiceSyncService $seaServiceSync = null,
        ?CrewMovementCorrectionValueSnapshot $snapshot = null,
    ) {
        $this->applier = $applier ?? app(ApplyCrewMovementCorrection::class);
        $this->tourSignoffRecalc = $tourSignoffRecalc ?? app(RecalculateTourSignoffAfterP4StartCorrection::class);
        $this->invariantGuard = $invariantGuard ?? app(CrewAssignmentInvariantGuard::class);
        $this->planningSync = $planningSync ?? app(SyncPlanningAssignmentFromCrewAssignment::class);
        $this->seaServiceSync = $seaServiceSync ?? app(SeaServiceSyncService::class);
        $this->snapshot = $snapshot ?? app(CrewMovementCorrectionValueSnapshot::class);
    }

    /**
     * Applies normalized proposed correction changes to the assignment and phase,
     * updates derived Tour of Duty data, checks invariants, synchronizes downstream
     * Planning, Sea Service, and Training records, and returns the captured applied values.
     *
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    public function execute(
        CrewAssignment $assignment,
        CrewAssignmentPhase $phase,
        array $normalized,
        User $actor,
        int $companyId,
    ): array {
        $this->applier->apply($assignment, $phase, $normalized);

        $assignment->refresh();
        $phase->refresh();

        $this->tourSignoffRecalc->handle($assignment, $phase, $normalized, $actor);

        $assignment->refresh();
        $phase->refresh();
        $assignment->load(['employee', 'phases', 'currentPhase', 'previousAssignment', 'planningAssignment']);

        $this->invariantGuard->assertValid($assignment);
        $this->planningSync->sync($assignment);

        if ($phase->phase_code === CrewPhaseCode::OnVessel
            && $phase->status === CrewPhaseStatus::Completed) {
            $synced = $this->seaServiceSync->syncFromPhase($phase->fresh(['assignment.employee', 'assignment.vessel']));

            if ($synced === null && $this->seaServiceSync->isEnabled($companyId)) {
                throw CrewMovementException::make(
                    'Approved correction would leave completed on-vessel sea service unsyncable.',
                    'correction_sea_service_unsyncable',
                );
            }
        }

        if ($phase->phase_code === CrewPhaseCode::Training
            && $phase->status === CrewPhaseStatus::Completed) {
            $employeeTraining = EmployeeTraining::query()
                ->where('source_crew_assignment_phase_id', $phase->id)
                ->lockForUpdate()
                ->first();

            if ($employeeTraining !== null) {
                $trainingUpdates = [];
                if (array_key_exists('actual_end_at', $normalized) && $phase->actual_end_at instanceof CarbonInterface) {
                    $timezone = CompanyTimezone::forCompanyId($companyId);
                    $trainingUpdates['issue_date'] = Carbon::parse($phase->actual_end_at)->timezone($timezone)->toDateString();
                }
                if (array_key_exists('details.provider', $normalized)) {
                    $trainingUpdates['institute_center'] = is_array($phase->details) ? ($phase->details['provider'] ?? null) : null;
                }
                if (array_key_exists('details.course_id', $normalized)) {
                    $trainingUpdates['course_id'] = (int) $normalized['details.course_id'];
                }
                if ($trainingUpdates !== []) {
                    $employeeTraining->update($trainingUpdates);
                }
            }
        }

        return $this->snapshot->capture($assignment, $phase, array_keys($normalized));
    }
}
