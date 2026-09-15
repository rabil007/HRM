import { Head } from '@inertiajs/react';
import { CrewAssignmentCreateForm } from '@/features/organization/crew/components/crew-assignment-create-form';
import type {
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';

export default function CrewAssignmentCreate({
    form_options,
    can,
    initial_row_count = 1,
}: {
    form_options: CrewAssignmentCreateFormOptions;
    can: CrewAssignmentPagePermissions;
    initial_row_count?: number;
}) {
    return (
        <>
            <Head title="Start Crew Assignment" />
            <CrewAssignmentCreateForm
                form_options={form_options}
                can={can}
                initial_row_count={initial_row_count}
            />
        </>
    );
}
