<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
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
     * }  $submitted  Unused for identity — assignment fields are authoritative.
     */
    public function handle(
        int $companyId,
        int $planningAssignmentId,
        CrewAssignment $assignment,
        array $submitted = [],
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

        $this->assertCompatibleWithAssignment($planning, $assignment);

        $planning->update([
            'crew_assignment_id' => $assignment->id,
        ]);
    }

    private function assertCompatibleWithAssignment(
        CrewPlanningAssignment $planning,
        CrewAssignment $assignment,
    ): void {
        if ($assignment->vessel_id === null) {
            throw ValidationException::withMessages([
                'vessel_id' => 'The crew assignment must have a vessel before linking a planning slot.',
            ]);
        }

        if ($assignment->rank_id === null) {
            throw ValidationException::withMessages([
                'rank_id' => 'The crew assignment must have a rank before linking a planning slot.',
            ]);
        }

        if ($planning->vessel_id !== null
            && (int) $planning->vessel_id !== (int) $assignment->vessel_id) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'The planning slot vessel does not match this crew assignment.',
            ]);
        }

        if ($planning->rank_id !== null
            && (int) $planning->rank_id !== (int) $assignment->rank_id) {
            throw ValidationException::withMessages([
                'planning_assignment_id' => 'The planning slot rank does not match this crew assignment.',
            ]);
        }

        $slotJoin = $planning->planned_join_date?->toDateString();
        $slotLeave = $planning->planned_leave_date?->toDateString();
        $assignmentJoin = $assignment->planned_join_at?->toDateString();
        $assignmentSignoff = $assignment->planned_signoff_at?->toDateString();

        // Named assignments may occupy a valid subset of the vacant slot window.
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
}
