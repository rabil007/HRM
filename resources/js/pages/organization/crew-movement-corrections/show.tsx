import { Head } from '@inertiajs/react';
import { CrewMovementCorrectionShowContent } from '@/features/organization/crew-movement-corrections/crew-movement-correction-show-content';
import type { CrewMovementCorrectionShowProps } from '@/features/organization/crew-movement-corrections/types';

export default function CrewMovementCorrectionShow(
    props: CrewMovementCorrectionShowProps,
) {
    return (
        <>
            <Head title={`Correction #${props.correction.id}`} />
            <CrewMovementCorrectionShowContent {...props} />
        </>
    );
}
