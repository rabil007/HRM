import { Head } from '@inertiajs/react';
import { CrewAssignmentCreateForm } from '@/features/organization/crew/components/crew-assignment-create-form';
import type {
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';

/** @deprecated Use `/organization/crew/create?mode=bulk` — kept for backward-compatible route rendering. */
export default function CrewAssignmentBulkCreate({
    form_options,
    can,
}: {
    form_options: CrewAssignmentCreateFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    return (
        <>
            <Head title="Start Crew Assignment" />
            <CrewAssignmentCreateForm
                form_options={form_options}
                can={can}
                initial_row_count={2}
            />
        </>
    );
}
