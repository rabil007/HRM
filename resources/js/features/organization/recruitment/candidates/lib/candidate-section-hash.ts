export type CandidateDetailHash = 'offer-jol' | 'joining' | 'interview';

export type CandidateDetailSectionKey = 'offer' | 'joining' | 'interview';

export function parseCandidateDetailHash(
    hash: string | null | undefined,
): CandidateDetailHash | null {
    const value = (hash ?? '').replace(/^#/, '').trim();

    if (value === 'offer-jol' || value === 'joining' || value === 'interview') {
        return value;
    }

    return null;
}

export function sectionKeyForHash(
    hash: CandidateDetailHash,
): CandidateDetailSectionKey {
    if (hash === 'offer-jol') {
        return 'offer';
    }

    return hash;
}

export function scrollToCandidateSection(
    hash: CandidateDetailHash,
    behavior: ScrollBehavior = 'smooth',
): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.getElementById(hash)?.scrollIntoView({
        behavior,
        block: 'start',
    });
}

/** Build the absolute candidate detail URL for a section hash. */
export function candidateDetailSectionHref(
    candidateId: number,
    hash: CandidateDetailHash,
): string {
    return `/organization/recruitment/candidates/${candidateId}#${hash}`;
}
