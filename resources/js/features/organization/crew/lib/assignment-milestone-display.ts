import type { CrewAssignmentDetail } from '../types.ts';

export type AssignmentMilestoneRow = {
    label: string;
    planned: string | null | undefined;
    actual: string | null | undefined;
};

/** Assignment-level planned/actual milestones shown in the Phase Timeline card. */
export function buildAssignmentMilestoneRows(
    assignment: Pick<
        CrewAssignmentDetail,
        | 'planned_arrival_at'
        | 'actual_arrival_at'
        | 'planned_join_at'
        | 'actual_join_at'
        | 'planned_travel_at'
        | 'planned_signoff_at'
        | 'actual_disembarkation_at'
    >,
): AssignmentMilestoneRow[] {
    return [
        {
            label: 'Arrival',
            planned: assignment.planned_arrival_at,
            actual: assignment.actual_arrival_at,
        },
        {
            label: 'Vessel Join',
            planned: assignment.planned_join_at,
            actual: assignment.actual_join_at,
        },
        {
            label: 'Travel',
            planned: assignment.planned_travel_at,
            actual: null,
        },
        {
            label: 'Sign-Off',
            planned: assignment.planned_signoff_at,
            actual: assignment.actual_disembarkation_at,
        },
    ];
}
