import type { CrewPayrollRow, CrewTimesheetSegment } from '../types';

export type MovementCategoryGroup = 'standby' | 'onsite';

export const STANDBY_CATEGORIES = [
    'sign_on_standby',
    'sign_off_standby',
] as const;
export const ONSITE_CATEGORIES = ['onsite'] as const;

export function categoryGroupCategories(
    group: MovementCategoryGroup,
): string[] {
    return group === 'standby'
        ? (STANDBY_CATEGORIES as unknown as string[])
        : (ONSITE_CATEGORIES as unknown as string[]);
}

export function defaultCategoryForGroup(group: MovementCategoryGroup): string {
    return group === 'standby' ? 'sign_on_standby' : 'onsite';
}

export type MovementPeriodDraftSegment = {
    key: string;
    pay_category: string;
    from_date: string;
    to_date: string;
    remarks: string;
};

export function inclusiveMovementDays(from: string, to: string): number | null {
    if (!from || !to || to < from) {
        return null;
    }

    const start = new Date(`${from}T00:00:00`);
    const end = new Date(`${to}T00:00:00`);
    const diff = Math.round((end.getTime() - start.getTime()) / 86_400_000);

    return diff + 1;
}

export type MovementRangePortion = {
    from_date: string;
    to_date: string;
    days: number;
    classification: 'prior' | 'current';
};

export type MovementRangeSplit = {
    prior: MovementRangePortion | null;
    current: MovementRangePortion | null;
    priorDays: number;
    currentDays: number;
    exceedsPeriodEnd: boolean;
};

/**
 * Splits a movement date range across the payroll period start so prior-period
 * days (arrears) can be previewed separately from current-period days.
 * Dates after period end are flagged; the server remains authoritative.
 */
export function splitMovementRangeAcrossPeriod(
    fromDate: string,
    toDate: string,
    periodStart: string,
    periodEnd: string,
): MovementRangeSplit | null {
    if (
        !fromDate ||
        !toDate ||
        !periodStart ||
        !periodEnd ||
        toDate < fromDate
    ) {
        return null;
    }

    const exceedsPeriodEnd = fromDate > periodEnd || toDate > periodEnd;

    if (fromDate > periodEnd) {
        return {
            prior: null,
            current: null,
            priorDays: 0,
            currentDays: 0,
            exceedsPeriodEnd: true,
        };
    }

    let prior: MovementRangePortion | null = null;
    let current: MovementRangePortion | null = null;

    if (fromDate < periodStart) {
        const priorTo =
            toDate < periodStart ? toDate : previousCalendarDay(periodStart);

        if (priorTo !== null && fromDate <= priorTo) {
            const days = inclusiveMovementDays(fromDate, priorTo);

            if (days !== null && days > 0) {
                prior = {
                    from_date: fromDate,
                    to_date: priorTo,
                    days,
                    classification: 'prior',
                };
            }
        }
    }

    const currentFrom = fromDate >= periodStart ? fromDate : periodStart;
    const currentTo = toDate <= periodEnd ? toDate : periodEnd;

    if (
        currentFrom <= currentTo &&
        currentFrom >= periodStart &&
        currentTo <= periodEnd
    ) {
        const days = inclusiveMovementDays(currentFrom, currentTo);

        if (days !== null && days > 0) {
            current = {
                from_date: currentFrom,
                to_date: currentTo,
                days,
                classification: 'current',
            };
        }
    }

    return {
        prior,
        current,
        priorDays: prior?.days ?? 0,
        currentDays: current?.days ?? 0,
        exceedsPeriodEnd,
    };
}

function previousCalendarDay(isoDate: string): string | null {
    const date = new Date(`${isoDate}T00:00:00`);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    date.setDate(date.getDate() - 1);

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

export function createEmptyMovementPeriodDraft(
    key: string,
    defaultCategory = 'onsite',
): MovementPeriodDraftSegment {
    return {
        key,
        pay_category: defaultCategory,
        from_date: '',
        to_date: '',
        remarks: '',
    };
}

export function segmentDraftsFromTimesheet(
    timesheet: CrewPayrollRow['timesheet'],
    group?: MovementCategoryGroup,
): MovementPeriodDraftSegment[] {
    const visibleCategories = group
        ? categoryGroupCategories(group)
        : undefined;

    const existing = timesheet?.segments ?? [];

    if (existing.length > 0) {
        const filtered = visibleCategories
            ? existing.filter((s) =>
                  visibleCategories.includes(s.pay_category ?? ''),
              )
            : existing;

        if (filtered.length > 0) {
            return filtered.map((segment, index) =>
                draftFromExistingSegment(segment, index),
            );
        }

        if (visibleCategories) {
            const defaultCat = visibleCategories[0] ?? 'onsite';

            return [
                createEmptyMovementPeriodDraft(`new-${Date.now()}`, defaultCat),
            ];
        }

        return existing.map((segment, index) =>
            draftFromExistingSegment(segment, index),
        );
    }

    const drafts: MovementPeriodDraftSegment[] = [];

    const maybePush = (
        category: string,
        from: string | null | undefined,
        to: string | null | undefined,
    ) => {
        if (!from && !to) {
            return;
        }

        if (visibleCategories && !visibleCategories.includes(category)) {
            return;
        }

        drafts.push({
            key: `legacy-${category}`,
            pay_category: category,
            from_date: from ?? '',
            to_date: to ?? '',
            remarks: '',
        });
    };

    maybePush(
        'sign_on_standby',
        timesheet?.sign_on_standby_from,
        timesheet?.sign_on_standby_to,
    );
    maybePush('onsite', timesheet?.onsite_from, timesheet?.onsite_to);
    maybePush(
        'sign_off_standby',
        timesheet?.sign_off_standby_from,
        timesheet?.sign_off_standby_to,
    );

    if (drafts.length === 0) {
        const defaultCat = group ? defaultCategoryForGroup(group) : 'onsite';

        drafts.push(
            createEmptyMovementPeriodDraft(`new-${Date.now()}`, defaultCat),
        );
    }

    return drafts;
}

/**
 * Returns segments that belong to the OTHER group (so they can be preserved
 * when only one group is being edited and saved).
 */
export function hiddenGroupSegmentDrafts(
    timesheet: CrewPayrollRow['timesheet'],
    group: MovementCategoryGroup,
): MovementPeriodDraftSegment[] {
    const hiddenCategories: string[] =
        group === 'standby'
            ? (ONSITE_CATEGORIES as unknown as string[])
            : (STANDBY_CATEGORIES as unknown as string[]);

    const existing = timesheet?.segments ?? [];

    return existing
        .filter((s) => hiddenCategories.includes(s.pay_category ?? ''))
        .map((segment, index) => draftFromExistingSegment(segment, index));
}

export function draftFromExistingSegment(
    segment: CrewTimesheetSegment,
    index: number,
): MovementPeriodDraftSegment {
    return {
        key: `existing-${segment.id}-${index}`,
        pay_category: segment.pay_category ?? 'onsite',
        from_date: segment.from_date ?? '',
        to_date: segment.to_date ?? '',
        remarks: segment.remarks ?? '',
    };
}
