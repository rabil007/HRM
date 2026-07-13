import { Head } from '@inertiajs/react';
import { TrainingEmployeeContent } from '@/features/organization/training/employee/training-employee-content';
import type { TrainingEmployeeBrowseProps } from '@/features/organization/training/types';

export default function TrainingEmployeeBrowse(
    props: TrainingEmployeeBrowseProps,
) {
    return (
        <>
            <Head title={`Training — ${props.employee.name}`} />
            <TrainingEmployeeContent {...props} />
        </>
    );
}
