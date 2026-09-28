import { Head } from '@inertiajs/react';
import { CrewReliefContent } from '@/features/reports/crew-relief/content';
import type { CrewReliefProps } from '@/features/reports/crew-relief/types';

export default function CrewReliefIndex(props: CrewReliefProps) {
    return (
        <>
            <Head title="Crew Relief Report" />
            <CrewReliefContent {...props} />
        </>
    );
}
