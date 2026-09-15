import { Head } from '@inertiajs/react';
import { BulkAddCrewForm } from '@/features/organization/crew/bulk-add/bulk-add-form';
import type {
    CrewAssignmentCreateFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';

export default function CrewAssignmentBulkCreate({
    form_options,
    can,
}: {
    form_options: CrewAssignmentCreateFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    return (
        <>
            <Head title="Bulk Add Crew" />
            <BulkAddCrewForm form_options={form_options} can={can} />
        </>
    );
}
