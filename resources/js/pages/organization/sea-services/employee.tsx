import { Head } from '@inertiajs/react';
import { SeaServicesEmployeeContent } from '@/features/organization/sea-services/employee/sea-services-employee-content';
import type { SeaServiceEmployeeBrowseProps } from '@/features/organization/sea-services/types';

export default function SeaServiceEmployeeBrowse(
    props: SeaServiceEmployeeBrowseProps,
) {
    return (
        <>
            <Head title={`Sea Services — ${props.employee.name}`} />
            <SeaServicesEmployeeContent {...props} />
        </>
    );
}
