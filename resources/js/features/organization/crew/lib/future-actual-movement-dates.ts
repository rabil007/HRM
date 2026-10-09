/**
 * Shared helpers for Record Now vs Schedule for Later timestamp UX.
 *
 * Record Now rejects future actual timestamps (unless a legacy testing override
 * remains enabled). Schedule for Later intentionally uses future instants and
 * never treats them as actual movements until automatic execution.
 */

export const TESTING_OVERRIDE_BANNER_MESSAGE =
    'Legacy Testing Override is still enabled for this company. Prefer Schedule for Later for future movements so actual status, accommodation, and Sea Service stay unchanged until execution.';

export const SCHEDULE_LATER_HELP =
    'Saving a schedule does not change the current phase, hotel stay, Sea Service, or payroll movement dates. The system executes automatically when due.';

export function resolveMovementOccurredAtMax(
    companyNow: string,
    allowFutureActualMovementDates: boolean,
    schedulingMode = false,
): string | undefined {
    if (schedulingMode || allowFutureActualMovementDates) {
        return undefined;
    }

    return companyNow;
}

export function resolveMovementOccurredAtMin(
    companyNow: string,
    schedulingMode = false,
): string | undefined {
    return schedulingMode ? companyNow : undefined;
}

export function shouldShowFutureMovementWarning(
    isFuture: boolean,
    allowFutureActualMovementDates: boolean,
    schedulingMode = false,
): boolean {
    if (schedulingMode) {
        return false;
    }

    return isFuture && !allowFutureActualMovementDates;
}

/**
 * Whether Step 2 / progress should stay blocked because a future actual
 * timestamp is present and the company testing override is OFF.
 */
export function shouldBlockFutureActualMovementDate(
    isFuture: boolean,
    allowFutureActualMovementDates: boolean,
    schedulingMode = false,
): boolean {
    if (schedulingMode) {
        return false;
    }

    return isFuture && !allowFutureActualMovementDates;
}

export function shouldShowTestingOverrideBanner(
    allowFutureActualMovementDates: boolean,
    schedulingMode = false,
): boolean {
    return allowFutureActualMovementDates && !schedulingMode;
}

export function isSchedulableMovementAction(
    action: string,
    schedulableActions: string[],
): boolean {
    return schedulableActions.includes(action);
}
