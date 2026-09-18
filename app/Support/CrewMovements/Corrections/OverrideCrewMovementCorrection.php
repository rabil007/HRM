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

final class OverrideCrewMovementCorrection
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

    /**
     * Atomically validates and applies an immediate crew movement correction override.
     *
     * @param  array<string, mixed>  $proposed
     */
    public function handle(
        CrewAssignment $assignment,
        CrewAssignmentPhase $phase,
        User $actor,
        int $companyId,
        array $proposed,
        string $reason,
    ): CrewMovementCorrection {
        $reason = trim($reason);

        if ($reason === '') {
            throw CrewMovementException::make('A correction reason is required.', 'correction_reason_required');
        }

        if (! $actor->can('crew_operations.corrections.override')) {
            throw CrewMovementException::make('You do not have permission to override crew movement corrections.', 'correction_override_forbidden');
        }

        PrivilegedTwoFactorPolicy::assertSatisfied($actor);

        return DB::transaction(function () use ($assignment, $phase, $actor, $companyId, $proposed, $reason): CrewMovementCorrection {
            $assignment = CrewAssignment::query()
                ->whereKey($assignment->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $phase = CrewAssignmentPhase::query()
                ->whereKey($phase->id)
                ->where('crew_assignment_id', $assignment->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

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

            $normalized = $this->validator->validateProposed(
                $assignment,
                $phase,
                $proposed,
            );

            $fields = array_keys($normalized);
            $originals = $this->snapshot->capture($assignment, $phase, $fields);
            $proposedValues = $this->snapshot->captureProposed($assignment, $phase, $normalized);

            $now = now();
            $correction = CrewMovementCorrection::query()->create([
                'company_id' => $assignment->company_id,
                'crew_assignment_id' => $assignment->id,
                'crew_assignment_phase_id' => $phase->id,
                'status' => CrewMovementCorrectionStatus::Approved,
                'original_values' => $originals,
                'proposed_values' => $proposedValues,
                'applied_values' => null,
                'reason' => $reason,
                'decision_notes' => 'Applied via direct correction override.',
                'requested_by' => $actor->id,
                'decided_by' => $actor->id,
                'requested_at' => $now,
                'decided_at' => $now,
            ]);

            $applied = $this->pipeline->execute(
                $assignment,
                $phase,
                $normalized,
                $actor,
                $companyId,
            );

            $correction->update([
                'applied_values' => $applied,
            ]);

            activity()
                ->performedOn($assignment)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'correction_override_applied',
                    'company_id' => $assignment->company_id,
                    'assignment_id' => $assignment->id,
                    'phase_id' => $phase->id,
                    'correction_id' => $correction->id,
                    'actor_id' => $actor->id,
                    'original_values' => $originals,
                    'applied_values' => $applied,
                    'reason' => $reason,
                ])
                ->tap(function ($activity) use ($assignment): void {
                    $activity->company_id = $assignment->company_id;
                })
                ->log('Crew movement correction override applied');

            return $correction->fresh(['phase', 'requester', 'decisionMaker', 'assignment']) ?? $correction;
        });
    }
}
