/**
 * Shared helpers for the company testing override that allows future
 * actual movement timestamps. Keep MovementOccurredAtField and related UI
 * aligned with CrewActualMovementTimestampGuard.
 */

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

export function shouldShowTestingOverrideBanner(
    allowFutureActualMovementDates: boolean,
): boolean {
    return allowFutureActualMovementDates;
}

/**
 * Planned ↔ Planned conflict actions are already filtered by instance policy
 * in allowed_actions. Do not also require create-page can.update / can.cancel.
 */
export function shouldShowPlannedConflictAction(
    allowedActions: readonly string[],
    action: 'edit_existing_plan' | 'cancel_existing_plan',
): boolean {
    return allowedActions.includes(action);
}
