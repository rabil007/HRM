import { Head } from '@inertiajs/react';
import { EmployeeProfileTemplatesContent } from '@/features/organization/employee-profile-templates';
import type { EmployeeProfileTemplate } from '@/features/organization/employee-profile-templates/types';

export default function EmployeeProfileTemplatesIndex({
    templates,
}: {
    templates: EmployeeProfileTemplate[];
}) {
    return (
        <>
            <Head title="Employee profile templates" />
            <EmployeeProfileTemplatesContent templates={templates} />
        </>
    );
}
