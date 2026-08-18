import type { CrewAssignmentListItem } from '@/features/organization/crew/types';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

export function reliefActionHref(
    assignment: CrewAssignmentListItem,
): string | null {
    const status = assignment.relief_status;

    if (!status || status === 'no_relief') {
        return crewPlanningIndex.url({
            query: {
                vessel_id: assignment.vessel?.id,
                rank_id: assignment.rank?.id,
                relieves_crew_assignment_id: assignment.id,
                planned_join_date:
                    assignment.planned_signoff_at ??
                    assignment.source_planned_signoff_date ??
                    undefined,
                open_create: 1,
            },
        });
    }

    if (status === 'relief_planned') {
        return crewPlanningIndex.url({
            query: {
                vessel_id: assignment.vessel?.id ?? undefined,
                rank_id: assignment.rank?.id ?? undefined,
                search: assignment.relief_employee?.name ?? undefined,
            },
        });
    }

    if (assignment.relief_crew_assignment_id) {
        return showAssignment.url(assignment.relief_crew_assignment_id);
    }

    return crewPlanningIndex.url({
        query: {
            vessel_id: assignment.vessel?.id ?? undefined,
            rank_id: assignment.rank?.id ?? undefined,
            search: assignment.relief_employee?.name ?? undefined,
        },
    });
}
