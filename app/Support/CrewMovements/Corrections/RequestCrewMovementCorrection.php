<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RequestCrewMovementCorrection
{
    public function __construct(
        private readonly ValidateCrewMovementCorrection $validator = new ValidateCrewMovementCorrection,
        private readonly CrewMovementCorrectionValueSnapshot $snapshot = new CrewMovementCorrectionValueSnapshot,
    ) {}

    /**
     * @param  array<string, mixed>  $proposed
     */
    public function handle(
        CrewAssignment $assignment,
        CrewAssignmentPhase $phase,
        User $requester,
        array $proposed,
        string $reason,
    ): CrewMovementCorrection {
        $reason = trim($reason);

        if ($reason === '') {
            throw CrewMovementException::make('A correction reason is required.', 'correction_reason_required');
        }

        return DB::transaction(function () use ($assignment, $phase, $requester, $proposed, $reason): CrewMovementCorrection {
            $assignment = CrewAssignment::query()
                ->whereKey($assignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $phase = CrewAssignmentPhase::query()
                ->whereKey($phase->id)
                ->where('crew_assignment_id', $assignment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $normalized = $this->validator->validateProposed($assignment, $phase, $proposed);
            $fields = array_keys($normalized);

            $correction = CrewMovementCorrection::query()->create([
                'company_id' => $assignment->company_id,
                'crew_assignment_id' => $assignment->id,
                'crew_assignment_phase_id' => $phase->id,
                'status' => CrewMovementCorrectionStatus::Pending,
                'original_values' => $this->snapshot->capture($assignment, $phase, $fields),
                'proposed_values' => $this->snapshot->captureProposed($assignment, $phase, $normalized),
                'applied_values' => null,
                'reason' => $reason,
                'decision_notes' => null,
                'requested_by' => $requester->id,
                'decided_by' => null,
                'requested_at' => now(),
                'decided_at' => null,
            ]);

            activity()
                ->performedOn($assignment)
                ->causedBy($requester)
                ->withProperties([
                    'event' => 'correction_requested',
                    'correction_id' => $correction->id,
                    'phase_id' => $phase->id,
                    'proposed_values' => $correction->proposed_values,
                    'reason' => $reason,
                ])
                ->log('Crew movement correction requested');

            return $correction->fresh(['phase', 'requester', 'assignment']) ?? $correction;
        });
    }
}
