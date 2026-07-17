import { Head } from '@inertiajs/react';
import { CrewMovementCorrectionsContent } from '@/features/organization/crew-movement-corrections/crew-movement-corrections-content';
import type { CrewMovementCorrectionsIndexProps } from '@/features/organization/crew-movement-corrections/types';

export default function CrewMovementCorrectionsIndex(
    props: CrewMovementCorrectionsIndexProps,
) {
    return (
        <>
            <Head title="Movement Corrections" />
            <CrewMovementCorrectionsContent {...props} />
        </>
    );
}
