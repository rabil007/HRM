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

/**
 * Native calendar maximum.
 *
 * `allowsFutureDateSelection` comes from the schedulable action itself.
 * It must not depend on scheduling mode, which only becomes true after a
 * future date is already selected.
 */
export function resolveMovementOccurredAtMax(
    companyNow: string,
    allowsFutureDateSelection = false,
): string | undefined {
    if (allowsFutureDateSelection) {
        return undefined;
    }

    return companyNow;
}

/**
 * Chronology minimums (for example disembarkation not before vessel joining)
 * are passed by the form. Scheduling mode must not lock the calendar to
 * company-now, or the operator cannot return to Record Movement.
 *
 * `preventPastSelection` is only for editing an existing schedule.
 */
export function resolveMovementOccurredAtMin(
    companyNow: string,
    options: {
        preventPastSelection?: boolean;
        chronologyMin?: string;
    } = {},
): string | undefined {
    if (!options.preventPastSelection) {
        return options.chronologyMin;
    }

    if (
        options.chronologyMin !== undefined &&
        options.chronologyMin > companyNow
    ) {
        return options.chronologyMin;
    }

    return companyNow;
}

/**
 * Warn when a future time is selected for an action that cannot be scheduled.
 */
export function shouldShowFutureMovementWarning(
    isFuture: boolean,
    allowsFutureDateSelection = false,
): boolean {
    return isFuture && !allowsFutureDateSelection;
}

/**
 * Block submit when a future time is selected for a non-schedulable action.
 */
export function shouldBlockFutureActualMovementDate(
    isFuture: boolean,
    allowsFutureDateSelection = false,
): boolean {
    return isFuture && !allowsFutureDateSelection;
}

export function isSchedulableMovementAction(
    action: string,
    schedulableActions: string[],
): boolean {
    return schedulableActions.includes(action);
}

/**
 * Future dates on a schedulable action use the schedule presentation
 * immediately. Editing an existing schedule stays in that presentation even
 * when the operator has not changed the time yet.
 */
export function resolveAutomaticSchedulingMode(
    selectedIsFuture: boolean,
    actionIsSchedulable: boolean,
    isEditingSchedule = false,
): boolean {
    return isEditingSchedule || (selectedIsFuture && actionIsSchedulable);
}

export function resolveMovementSubmitLabel(
    schedulingMode: boolean,
    isEditingSchedule: boolean,
    recordLabel: string,
): string {
    if (isEditingSchedule) {
        return 'Save Changes';
    }

    if (schedulingMode) {
        return 'Schedule Movement';
    }

    return recordLabel;
}

export function formatScheduledMovementExplanation(
    scheduledAtDisplay: string,
): string {
    return `Scheduled for ${scheduledAtDisplay}. The current phase will remain unchanged until this movement executes.`;
}

export const RECORD_NOW_EXPLANATION =
    'Record this movement now. The operational phase will update immediately.';
