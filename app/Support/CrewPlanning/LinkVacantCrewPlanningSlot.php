<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Atomically links a vacant CrewPlanningAssignment slot to a CrewAssignment.
 *
 * CrewPlanningAssignment remains a vacant/unfilled planning slot until linked.
 * Named employees live on CrewAssignment only.
 */
final class LinkVacantCrewPlanningSlot
{
    /**
     * @param  array{
     *     vessel_id?: int|null,
     *     rank_id?: int|null,
     *     planned_join_at?: string|null,
     *     planned_signoff_at?: string|null,
     * }  $submitted
     */
    public function handle(
        int $companyId,
        int $planningAssignmentId,
        CrewAssignment $assignment,
        array $submitted,
        ?User $actor = null,
    ): void {
        if ($actor === null || ! $actor->can('crew_operations.planning.view')) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'You are not authorized to link a planning slot.',
            ]);
        }

        $planning = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereKey($planningAssignmentId)
            ->lockForUpdate()
            ->first();

        if ($planning === null) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'The selected planning slot could not be found.',
            ]);
        }

        CrewPlanningAssignmentAccess::assertInCompany($planning, $companyId, $actor);

        if ($planning->crew_assignment_id !== null) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'This planning slot is already linked to a crew assignment.',
            ]);
        }

        if ($planning->employee_id !== null) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'Only vacant planning slots can be linked to a crew assignment.',
            ]);
        }

        $this->assertCompatibleContext($planning, $submitted);

        $planning->update([
            'crew_assignment_id' => $assignment->id,
        ]);
    }

    /**
     * @param  array{
     *     vessel_id?: int|null,
     *     rank_id?: int|null,
     *     planned_join_at?: string|null,
     *     planned_signoff_at?: string|null,
     * }  $submitted
     */
    private function assertCompatibleContext(CrewPlanningAssignment $planning, array $submitted): void
    {
        $submittedVesselId = isset($submitted['vessel_id']) && $submitted['vessel_id'] !== null && $submitted['vessel_id'] !== ''
            ? (int) $submitted['vessel_id']
            : null;
        $submittedRankId = isset($submitted['rank_id']) && $submitted['rank_id'] !== null && $submitted['rank_id'] !== ''
            ? (int) $submitted['rank_id']
            : null;

        if ($planning->vessel_id !== null && $submittedVesselId !== null
            && (int) $planning->vessel_id !== $submittedVesselId) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'The planning slot vessel does not match this crew assignment.',
            ]);
        }

        if ($planning->rank_id !== null && $submittedRankId !== null
            && (int) $planning->rank_id !== $submittedRankId) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'The planning slot rank does not match this crew assignment.',
            ]);
        }

        $slotJoin = $planning->planned_join_date?->toDateString();
        $slotLeave = $planning->planned_leave_date?->toDateString();
        $assignmentJoin = $this->toDateString($submitted['planned_join_at'] ?? null);
        $assignmentSignoff = $this->toDateString($submitted['planned_signoff_at'] ?? null);

        // Named assignments may occupy a valid subset of the vacant slot window.
        // Each boundary is checked independently (equal join must still respect leave).
        if ($slotJoin !== null && $assignmentJoin !== null && $assignmentJoin < $slotJoin) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'The planning slot dates are not compatible with this crew assignment.',
            ]);
        }

        if ($slotLeave !== null && $assignmentJoin !== null && $assignmentJoin > $slotLeave) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'The planning slot dates are not compatible with this crew assignment.',
            ]);
        }

        if ($slotLeave !== null && $assignmentSignoff !== null && $assignmentSignoff > $slotLeave) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'The planning slot dates are not compatible with this crew assignment.',
            ]);
        }
    }

    private function toDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toDateString();
    }
}
