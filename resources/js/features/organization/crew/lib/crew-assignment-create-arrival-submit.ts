import {
    nowInCompanyDate,
    safeCompanyTimezone,
} from '../../../../lib/company-timezone.ts';
import {
    ACTUAL_ARRIVAL_REQUIRED_MESSAGE,
    normalizeArrivalDate,
} from './already-arrived-start.ts';
import {
    normalizeCreateDate,
    parseValidCreateCalendarDate,
} from './crew-assignment-create-date-validation.ts';

export type AlreadyArrivedArrivalDecision =
    | { type: 'submit' }
    | { type: 'confirm_pre_arrival'; plannedArrivalDate: string }
    | { type: 'client_error'; message: string };

export function shouldDefaultSingleCreateArrivalDate(options: {
    fromPlanning: boolean;
    initialRowCount: number;
    hasRestoredBulkSession: boolean;
}): boolean {
    return (
        !options.fromPlanning &&
        options.initialRowCount === 1 &&
        !options.hasRestoredBulkSession
    );
}

export function resolveInitialRowPlannedArrival(
    rowIndex: number,
    current: string | null | undefined,
    defaultArrivalDate: string | undefined,
    allowDefaultArrival: boolean,
): string | null {
    if (
        allowDefaultArrival &&
        rowIndex === 0 &&
        defaultArrivalDate &&
        (current == null || current === '')
    ) {
        return defaultArrivalDate;
    }

    return current ?? null;
}

export function resolveDefaultCreateArrivalDate(options: {
    companyTimezone: string;
    planningArrival?: string | null;
    restoredArrival?: string | null;
    referenceDate?: Date;
}): string {
    const planning = normalizeArrivalDate(options.planningArrival);

    if (planning !== '') {
        return planning;
    }

    const restored = normalizeArrivalDate(options.restoredArrival);

    if (restored !== '') {
        return restored;
    }

    return nowInCompanyDate(
        safeCompanyTimezone(options.companyTimezone),
        options.referenceDate,
    );
}

export function isCalendarDateAfterCompanyToday(
    arrival: string | null | undefined,
    companyTimezone: string,
    referenceDate?: Date,
): boolean {
    const valid = parseValidCreateCalendarDate(arrival);

    if (valid === null) {
        return false;
    }

    const today = nowInCompanyDate(
        safeCompanyTimezone(companyTimezone),
        referenceDate,
    );

    return valid > today;
}

export function resolveAlreadyArrivedSubmitDecision(
    arrival: string | null | undefined,
    companyTimezone: string,
    referenceDate?: Date,
): AlreadyArrivedArrivalDecision {
    const normalized = normalizeCreateDate(arrival);

    if (normalized === '') {
        return {
            type: 'client_error',
            message: ACTUAL_ARRIVAL_REQUIRED_MESSAGE,
        };
    }

    const valid = parseValidCreateCalendarDate(normalized);

    if (valid === null) {
        return {
            type: 'client_error',
            message: ACTUAL_ARRIVAL_REQUIRED_MESSAGE,
        };
    }

    if (
        isCalendarDateAfterCompanyToday(valid, companyTimezone, referenceDate)
    ) {
        return {
            type: 'confirm_pre_arrival',
            plannedArrivalDate: valid,
        };
    }

    return { type: 'submit' };
}
