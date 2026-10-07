/**
 * Central switch for the Recruitment Requirement Hold capability.
 * Keep false to hide "Put on hold" entry points while preserving backend routes
 * and recovery Resume for requirements already On Hold.
 */
export const REQUIREMENT_HOLD_FEATURE_ENABLED = false;

export function isRequirementHoldActionVisible(canHold: boolean): boolean {
    return REQUIREMENT_HOLD_FEATURE_ENABLED && canHold;
}

export function isRequirementResumeActionVisible(input: {
    canResume: boolean;
    status: string;
}): boolean {
    if (!input.canResume) {
        return false;
    }

    // Recovery-only Resume when Hold UI is disabled: only for existing On Hold records.
    if (!REQUIREMENT_HOLD_FEATURE_ENABLED) {
        return input.status === 'on_hold';
    }

    return true;
}
