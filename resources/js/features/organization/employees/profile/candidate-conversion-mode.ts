import type {
    CandidateConversionContext,
    EmployeeTab,
} from '@/pages/organization/employee-page.types';

/**
 * Determines whether the current employee page session is in candidate-conversion mode.
 */
export function isCandidateConversionMode(
    candidateContext?: CandidateConversionContext | null,
): boolean {
    return Boolean(
        candidateContext &&
        typeof candidateContext.candidate_id === 'number' &&
        candidateContext.candidate_id > 0,
    );
}

/**
 * Checks whether an employee tab is a record tab that should be unavailable during candidate conversion.
 * Personal details (profile fields) remain editable for HR review; all record tabs require finalized conversion.
 */
export function isRecordTabUnavailableInConversion(
    tab: EmployeeTab,
    candidateContext?: CandidateConversionContext | null,
): boolean {
    return isCandidateConversionMode(candidateContext) && tab !== 'personal';
}

export const CANDIDATE_CONVERSION_UNAVAILABLE_TAB_MESSAGE =
    'Create the employee first to add these records.';
