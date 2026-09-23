import { Head } from '@inertiajs/react';
import { RequirementsShowContent } from '@/features/organization/recruitment/requirements/requirements-show-content';
import type { RequirementShowProps } from '@/types/recruitment';

export default function RequirementsShow(props: RequirementShowProps) {
    return (
        <>
            <Head
                title={`Requirement — ${props.requirement.requirement_number}`}
            />
            <RequirementsShowContent {...props} />
        </>
    );
}
