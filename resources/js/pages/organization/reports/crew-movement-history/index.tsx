import { Head } from '@inertiajs/react';
import { CrewMovementHistoryContent } from '@/features/reports/crew-movement-history/content';
import type { CrewMovementHistoryProps } from '@/features/reports/crew-movement-history/types';

export default function CrewMovementHistoryIndex(
    props: CrewMovementHistoryProps,
) {
    return (
        <>
            <Head title="Crew Movement History" />
            <CrewMovementHistoryContent {...props} />
        </>
    );
}
