/**
 * Shared helpers for the company testing override that allows future
 * actual movement timestamps. Keep MovementOccurredAtField and related UI
 * aligned with CrewActualMovementTimestampGuard.
 */

export const TESTING_OVERRIDE_BANNER_MESSAGE =
    'Testing Mode: Future-dated movements are recorded immediately and may update current crew status, accommodation, Sea Service and reports. Use test records only.';

export function resolveMovementOccurredAtMax(
    companyNow: string,
    allowFutureActualMovementDates: boolean,
): string | undefined {
    return allowFutureActualMovementDates ? undefined : companyNow;
}

export function shouldShowFutureMovementWarning(
    isFuture: boolean,
    allowFutureActualMovementDates: boolean,
): boolean {
    return isFuture && !allowFutureActualMovementDates;
}

/**
 * Whether Step 2 / progress should stay blocked because a future actual
 * timestamp is present and the company testing override is OFF.
 */
export function shouldBlockFutureActualMovementDate(
    isFuture: boolean,
    allowFutureActualMovementDates: boolean,
): boolean {
    return isFuture && !allowFutureActualMovementDates;
}

export function shouldShowTestingOverrideBanner(
    allowFutureActualMovementDates: boolean,
): boolean {
    return allowFutureActualMovementDates;
}
