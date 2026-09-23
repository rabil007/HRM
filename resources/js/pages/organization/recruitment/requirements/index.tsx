import { Head } from '@inertiajs/react';
import { RequirementsContent } from '@/features/organization/recruitment/requirements/requirements-content';
import type { RequirementIndexProps } from '@/types/recruitment';

export default function RequirementsIndex(props: RequirementIndexProps) {
    return (
        <>
            <Head title="Recruitment Requirements" />
            <RequirementsContent {...props} />
        </>
    );
}
