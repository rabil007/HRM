<?php

namespace App\Support\CrewMovements\Corrections;

use App\Enums\CrewMovementCorrectionStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewMovementCorrection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CancelCrewMovementCorrection
{
    public function __construct(
        private readonly ValidateCrewMovementCorrection $validator = new ValidateCrewMovementCorrection,
    ) {}

    public function handle(
        CrewMovementCorrection $correction,
        User $actor,
        int $companyId,
        ?string $decisionNotes = null,
    ): CrewMovementCorrection {
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

            $isRequester = (int) $correction->requested_by === (int) $actor->id;
            $canApprove = $actor->can('crew_operations.corrections.approve');
            $canOverride = $actor->can('crew_operations.corrections.override');

            if (! $isRequester && ! $canApprove && ! $canOverride) {
                throw CrewMovementException::make(
                    'You are not allowed to cancel this correction.',
                    'correction_cancel_forbidden',
                );
            }

            $correction->fill([
                'status' => CrewMovementCorrectionStatus::Cancelled,
                'decision_notes' => $decisionNotes !== null && trim($decisionNotes) !== ''
                    ? trim($decisionNotes)
                    : null,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);
            $correction->save();

            activity()
                ->performedOn($assignment)
                ->causedBy($actor)
                ->withProperties([
                    'event' => 'correction_cancelled',
                    'correction_id' => $correction->id,
                    'decision_notes' => $correction->decision_notes,
                ])
                ->log('Crew movement correction cancelled');

            return $correction->fresh(['phase', 'requester', 'decisionMaker', 'assignment']) ?? $correction;
        });
    }
}
