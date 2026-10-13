/**
 * Helpers for automatic immediate vs scheduled movement UX.
 *
 * Operators pick a movement date/time only. Past/current record immediately;
 * future times create a scheduled movement. No testing override and no manual
 * Record Now / Schedule for Later mode selector.
 */

export const SCHEDULE_PERMISSION_MESSAGE =
    'You do not have permission to schedule future movements. Choose a current or past date, or ask an administrator for scheduling access.';

export const PENDING_SCHEDULE_BLOCK_MESSAGE =
    'This assignment already has a pending scheduled movement. Edit, reschedule, or cancel it before creating another.';

export function resolveMovementOccurredAtMax(
    companyNow: string,
    schedulingMode = false,
): string | undefined {
    if (schedulingMode) {
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

/**
 * Warn when a future time is selected for an action that cannot be scheduled.
 */
export function shouldShowFutureMovementWarning(
    isFuture: boolean,
    schedulingMode = false,
): boolean {
    return isFuture && !schedulingMode;
}

/**
 * Block submit when a future time is selected for a non-schedulable action.
 */
export function shouldBlockFutureActualMovementDate(
    isFuture: boolean,
    schedulingMode = false,
): boolean {
    return isFuture && !schedulingMode;
}

export function isSchedulableMovementAction(
    action: string,
    schedulableActions: string[],
): boolean {
    return schedulableActions.includes(action);
}

export function formatScheduledMovementExplanation(
    scheduledAtDisplay: string,
): string {
    return `Scheduled for ${scheduledAtDisplay}. The current phase will remain unchanged until this movement executes.`;
}

export const RECORD_NOW_EXPLANATION =
    'Record this movement now. The operational phase will update immediately.';
