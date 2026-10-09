import { Head } from '@inertiajs/react';
import { CandidatesContent } from '@/features/organization/recruitment/candidates/candidates-content';
import type { CandidateIndexProps } from '@/features/organization/recruitment/candidates/types';

export default function CandidatesIndex(props: CandidateIndexProps) {
    return (
        <>
            <Head title="Candidates" />
            <CandidatesContent {...props} />
        </>
    );
}
