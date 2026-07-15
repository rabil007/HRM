import { Head } from '@inertiajs/react';
import { SeaServicesContent } from '@/features/organization/sea-services/sea-services-content';
import type { SeaServicesIndexProps } from '@/features/organization/sea-services/types';

export default function SeaServicesIndex(props: SeaServicesIndexProps) {
    return (
        <>
            <Head title="Sea Services" />
            <SeaServicesContent {...props} />
        </>
    );
}
