<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewMovementCorrection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RejectCrewMovementCorrection
{
    public function __construct(
        private readonly ValidateCrewMovementCorrection $validator = new ValidateCrewMovementCorrection,
    ) {}

    public function handle(
        CrewMovementCorrection $correction,
        User $actor,
        int $companyId,
        string $decisionNotes,
    ): CrewMovementCorrection {
        $decisionNotes = trim($decisionNotes);

        if ($decisionNotes === '') {
            throw CrewMovementException::make(
                'A rejection reason is required.',
                'correction_rejection_reason_required',
            );
        }

        return DB::transaction(function () use ($correction, $actor, $companyId, $decisionNotes): CrewMovementCorrection {
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

            $correction->fill([
                'status' => CrewMovementCorrectionStatus::Rejected,
                'decision_notes' => $decisionNotes,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);
            $correction->save();

            activity()
                ->performedOn($assignment)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'correction_rejected',
                    'correction_id' => $correction->id,
                    'decision_notes' => $decisionNotes,
                ])
                ->log('Crew movement correction rejected');

            return $correction->fresh(['phase', 'requester', 'decisionMaker', 'assignment']) ?? $correction;
        });
    }
}
