import { Head } from '@inertiajs/react';
import { CrewAssignmentCreateForm } from '@/features/organization/crew/components/crew-assignment-create-form';
import type {
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
    CrewPlanningBackQuery,
    CrewPlanningStartContext,
} from '@/features/organization/crew/types';

export default function CrewAssignmentCreate({
    form_options,
    can,
    initial_row_count = 1,
    intent = null,
    prefill = null,
    planning_context = null,
    planning_back_query = null,
}: {
    form_options: CrewAssignmentCreateFormOptions;
    can: CrewAssignmentPagePermissions;
    initial_row_count?: number;
    intent?: 'plan' | 'start' | null;
    prefill?: {
        employee_id?: number | null;
        vessel_id?: number | null;
        rank_id?: number | null;
        client_id?: number | null;
        planned_join_at?: string | null;
        planned_signoff_at?: string | null;
        relieves_crew_assignment_id?: number | null;
    } | null;
    planning_context?: CrewPlanningStartContext | null;
    planning_back_query?: CrewPlanningBackQuery | null;
}) {
    const pageTitle =
        intent === 'plan' ? 'Plan Crew Assignment' : 'Start Crew Assignment';

    return (
        <>
            <Head title={pageTitle} />
            <CrewAssignmentCreateForm
                form_options={form_options}
                can={can}
                initial_row_count={initial_row_count}
                intent={intent}
                prefill={prefill}
                planning_context={planning_context}
                planning_back_query={planning_back_query}
            />
        </>
    );
}
