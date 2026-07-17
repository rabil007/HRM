<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeSeaService;
use App\Models\User;
use App\Support\CrewMovements\CrewAssignmentInvariantGuard;
use App\Support\CrewMovements\SyncSeaServiceFromCrewAssignment;
use App\Support\CrewPlanning\SyncPlanningAssignmentFromCrewAssignment;
use Illuminate\Support\Facades\DB;

final class ApproveCrewMovementCorrection
{
    public function __construct(
        private readonly ValidateCrewMovementCorrection $validator = new ValidateCrewMovementCorrection,
        private readonly CrewMovementCorrectionValueSnapshot $snapshot = new CrewMovementCorrectionValueSnapshot,
        private readonly ApplyCrewMovementCorrection $applier = new ApplyCrewMovementCorrection,
        private readonly CrewAssignmentInvariantGuard $invariantGuard = new CrewAssignmentInvariantGuard,
        private readonly SyncPlanningAssignmentFromCrewAssignment $planningSync = new SyncPlanningAssignmentFromCrewAssignment,
        private readonly SyncSeaServiceFromCrewAssignment $seaServiceSync = new SyncSeaServiceFromCrewAssignment,
    ) {}

    public function handle(
        CrewMovementCorrection $correction,
        User $approver,
        int $companyId,
        ?string $decisionNotes = null,
    ): CrewMovementCorrection {
        $result = DB::transaction(function () use ($correction, $approver, $companyId, $decisionNotes): CrewMovementCorrection {
            $assignment = CrewAssignment::query()
                ->whereKey($correction->crew_assignment_id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $correction = CrewMovementCorrection::query()
                ->whereKey($correction->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validator->assertTenant($correction, $companyId);
            $this->validator->assertPending($correction);

            if ((int) $correction->requested_by === (int) $approver->id
                && ! $approver->can('crew_operations.corrections.override')) {
                throw CrewMovementException::make(
                    'You cannot approve your own correction request.',
                    'correction_self_approval',
                );
            }

            $phase = null;

            if ($correction->crew_assignment_phase_id !== null) {
                $phase = CrewAssignmentPhase::query()
                    ->whereKey($correction->crew_assignment_phase_id)
                    ->where('crew_assignment_id', $assignment->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if ($phase === null) {
                throw CrewMovementException::make(
                    'Correction phase is required.',
                    'correction_phase_required',
                );
            }

            CrewPlanningAssignment::query()
                ->withTrashed()
                ->where('crew_assignment_id', $assignment->id)
                ->lockForUpdate()
                ->first();

            EmployeeSeaService::query()
                ->withTrashed()
                ->where('crew_assignment_phase_id', $phase->id)
                ->lockForUpdate()
                ->get();

            $originals = $correction->original_values ?? [];

            if (! $this->snapshot->valuesMatch($originals, $assignment, $phase)) {
                throw CrewMovementException::make(
                    'The movement data changed after this correction was requested. Review and re-request.',
                    'correction_stale_originals',
                );
            }

            $proposedRaw = [];
            foreach ($correction->proposed_values ?? [] as $field => $entry) {
                $proposedRaw[$field] = is_array($entry) && array_key_exists('value', $entry)
                    ? $entry['value']
                    : $entry;
            }

            $normalized = $this->validator->validateProposed(
                $assignment,
                $phase,
                $proposedRaw,
                $correction->id,
            );

            $this->applier->apply($assignment, $phase, $normalized);

            $assignment->refresh();
            $phase->refresh();
            $assignment->load(['employee', 'phases', 'currentPhase', 'previousAssignment', 'planningAssignment']);

            $this->invariantGuard->assertValid($assignment);
            $this->planningSync->sync($assignment);

            if ($phase->phase_code === CrewPhaseCode::OnVessel
                && $phase->status === CrewPhaseStatus::Completed) {
                $synced = $this->seaServiceSync->syncFromPhase($phase->fresh(['assignment.employee', 'assignment.vessel']));

                if ($synced === null) {
                    throw CrewMovementException::make(
                        'Approved correction would leave completed on-vessel sea service unsyncable.',
                        'correction_sea_service_unsyncable',
                    );
                }
            }

            $applied = $this->snapshot->capture($assignment, $phase, array_keys($normalized));

            $correction->fill([
                'status' => CrewMovementCorrectionStatus::Approved,
                'applied_values' => $applied,
                'decision_notes' => $decisionNotes !== null && trim($decisionNotes) !== ''
                    ? trim($decisionNotes)
                    : null,
                'decided_by' => $approver->id,
                'decided_at' => now(),
            ]);
            $correction->save();

            activity()
                ->performedOn($assignment)
                ->causedBy($approver)
                ->withProperties([
                    'event' => 'correction_approved',
                    'correction_id' => $correction->id,
                    'phase_id' => $phase->id,
                    'applied_values' => $applied,
                    'decision_notes' => $correction->decision_notes,
                ])
                ->log('Crew movement correction approved');

            return $correction->fresh(['phase', 'requester', 'decisionMaker', 'assignment']) ?? $correction;
        });

        return $result;
    }
}
