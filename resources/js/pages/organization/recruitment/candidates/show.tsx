import { Head } from '@inertiajs/react';
import { CandidatesShowContent } from '@/features/organization/recruitment/candidates/candidates-show-content';
import type { CandidateShowProps } from '@/features/organization/recruitment/candidates/types';

export default function CandidateShow(props: CandidateShowProps) {
    return (
        <>
            <Head title={props.candidate.name} />
            <CandidatesShowContent {...props} />
        </>
    );
}
