<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\Auth\PrivilegedTwoFactorPolicy;
use Illuminate\Support\Facades\DB;

final class ApproveCrewMovementCorrection
{
    private readonly ValidateCrewMovementCorrection $validator;

    private readonly CrewMovementCorrectionValueSnapshot $snapshot;

    private readonly ApplyCrewMovementCorrectionPipeline $pipeline;

    public function __construct(
        ?ValidateCrewMovementCorrection $validator = null,
        ?CrewMovementCorrectionValueSnapshot $snapshot = null,
        ?ApplyCrewMovementCorrectionPipeline $pipeline = null,
    ) {
        $this->validator = $validator ?? app(ValidateCrewMovementCorrection::class);
        $this->snapshot = $snapshot ?? app(CrewMovementCorrectionValueSnapshot::class);
        $this->pipeline = $pipeline ?? app(ApplyCrewMovementCorrectionPipeline::class);
    }

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

            if ((int) $correction->requested_by === (int) $approver->id) {
                if (! $approver->can('crew_operations.corrections.override')) {
                    throw CrewMovementException::make(
                        'You cannot approve your own correction request.',
                        'correction_self_approval',
                    );
                }

                PrivilegedTwoFactorPolicy::assertSatisfied($approver);
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

            EmployeeTraining::query()
                ->where('source_crew_assignment_phase_id', $phase->id)
                ->lockForUpdate()
                ->first();

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

            $applied = $this->pipeline->execute(
                $assignment,
                $phase,
                $normalized,
                $approver,
                $companyId,
            );

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
