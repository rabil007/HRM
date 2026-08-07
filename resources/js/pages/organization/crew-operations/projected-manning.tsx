import { Head } from '@inertiajs/react';
import { ProjectedManningContent } from '@/features/organization/crew-operations/projected-manning';
import type { ProjectedManningPageProps } from '@/features/organization/crew-operations/projected-manning/types';

export default function ProjectedManningPage(props: ProjectedManningPageProps) {
    return (
        <>
            <Head title="Projected Manning" />
            <ProjectedManningContent {...props} />
        </>
    );
}
