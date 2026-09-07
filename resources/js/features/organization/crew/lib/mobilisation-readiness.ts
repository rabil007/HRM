import type { CrewMobilisationReadiness } from '../types';

export function hasConfiguredMobilisationChecks(
    readiness: CrewMobilisationReadiness,
): boolean {
    return readiness.checks_total > 0;
}

export function mobilisationReadinessPresentationLabel(
    readiness: CrewMobilisationReadiness,
): string {
    if (!hasConfiguredMobilisationChecks(readiness)) {
        return 'No Checks Configured';
    }

    return readiness.status_label;
}

export function isMobilisationReadinessProblem(
    readiness: CrewMobilisationReadiness,
): boolean {
    return readiness.status === 'not_ready' || readiness.status === 'attention';
}

export function mobilisationReadinessTone(
    readiness: CrewMobilisationReadiness,
): 'ready' | 'attention' | 'not_ready' | 'not_assessed' {
    if (!hasConfiguredMobilisationChecks(readiness)) {
        return 'not_assessed';
    }

    if (readiness.status === 'not_ready' || readiness.status === 'attention') {
        return readiness.status;
    }

    return 'ready';
}
