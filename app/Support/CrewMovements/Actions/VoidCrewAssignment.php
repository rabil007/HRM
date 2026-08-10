<?php

namespace App\Support\CrewMovements\Actions;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\CrewMovements\CrewAssignmentVoidGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VoidCrewAssignment
{
    public function __construct(
        private readonly CrewAssignmentVoidGuard $guard,
    ) {}

    public function handle(
        int $companyId,
        int $assignmentId,
        User $actor,
        string $reason,
    ): CrewAssignment {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'void_reason' => 'A void reason is required.',
            ]);
        }

        return DB::transaction(function () use ($companyId, $assignmentId, $actor, $reason): CrewAssignment {
            $assignment = CrewAssignment::query()
                ->withTrashed()
                ->where('company_id', $companyId)
                ->whereKey($assignmentId)
                ->lockForUpdate()
                ->with(['currentPhase'])
                ->first();

            if ($assignment === null) {
                abort(404);
            }

            $this->guard->assertCanVoid($assignment, $companyId);

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
            );

            return $assignment;
        });
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

        // Assignment-linked planning bars are derived / disposable for void cleanup.
        $linked->delete();
    }

    private function logVoid(
        CrewAssignment $assignment,
        int $companyId,
        User $actor,
        string $reason,
        ?string $previousStatus,
        ?string $previousPhase,
    ): void {
        $activity = activity()
            ->performedOn($assignment)
            ->causedBy($actor)
            ->withProperties([
                'event' => 'crew_assignment_voided',
                'company_id' => $companyId,
                'crew_assignment_id' => (int) $assignment->id,
                'assignment_no' => $assignment->assignment_no,
                'actor_user_id' => (int) $actor->id,
                'void_reason' => $reason,
                'previous_status' => $previousStatus,
                'previous_phase_code' => $previousPhase,
                'timestamp' => now()->toIso8601String(),
            ])
            ->log('Crew assignment voided as erroneous');

        $activity->forceFill(['company_id' => $companyId])->save();
    }
}
