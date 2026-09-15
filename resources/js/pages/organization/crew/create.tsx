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
    planning_context = null,
    planning_back_query = null,
}: {
    form_options: CrewAssignmentCreateFormOptions;
    can: CrewAssignmentPagePermissions;
    initial_row_count?: number;
    planning_context?: CrewPlanningStartContext | null;
    planning_back_query?: CrewPlanningBackQuery | null;
}) {
    return (
        <>
            <Head title="Start Crew Assignment" />
            <CrewAssignmentCreateForm
                form_options={form_options}
                can={can}
                initial_row_count={initial_row_count}
                planning_context={planning_context}
                planning_back_query={planning_back_query}
            />
        </>
    );
}
