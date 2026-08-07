import type { CrewAssignmentFormOptions } from '../types';

export const TOUR_OF_DUTY_SOURCE_LABELS: Record<string, string> = {
    global_rank_default: 'Global Rank Default',
    company_rank_policy: 'Company Rank Policy',
    assignment_override: 'Assignment Override',
};

export type CrewRankTourOption = CrewAssignmentFormOptions['ranks'][number];

export function tourSourceLabel(source: string | null | undefined): string {
    if (!source) {
        return 'Not configured';
    }

    return TOUR_OF_DUTY_SOURCE_LABELS[source] ?? source;
}

export function resolveJoinTourDays(
    rank: CrewRankTourOption | undefined,
    overrideDays: number | null | '' | undefined,
): number | null {
    if (
        overrideDays !== null &&
        overrideDays !== '' &&
        overrideDays !== undefined &&
        Number(overrideDays) > 0
    ) {
        return Number(overrideDays);
    }

    const resolved = rank?.resolved_tour_of_duty_days;

    return resolved != null && resolved > 0 ? resolved : null;
}

export function resolveJoinTourSource(
    rank: CrewRankTourOption | undefined,
    overrideDays: number | null | '' | undefined,
): string | null {
    if (
        overrideDays !== null &&
        overrideDays !== '' &&
        overrideDays !== undefined &&
        Number(overrideDays) > 0
    ) {
        return 'assignment_override';
    }

    return rank?.resolved_tour_of_duty_source ?? null;
}

/** Calendar-day addition for display-only suggested sign-off (backend is authoritative). */
export function suggestedPlannedSignoffDate(
    joinDate: string,
    tourDays: number,
): string | null {
    if (!joinDate || tourDays <= 0) {
        return null;
    }

    const date = new Date(`${joinDate}T12:00:00`);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    date.setDate(date.getDate() + tourDays);

    return date.toISOString().slice(0, 10);
}

export const CREW_TOUR_STATUS_FILTER_OPTIONS = [
    { value: '', label: 'All tour statuses' },
    { value: 'due_within_30_days', label: 'Due within 30 days' },
    { value: 'due_within_14_days', label: 'Due within 14 days' },
    { value: 'due_within_7_days', label: 'Due within 7 days' },
    { value: 'due_today', label: 'Due today' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'missing_tour_rule', label: 'Missing Tour of Duty rule' },
    { value: 'missing_signoff', label: 'Missing planned sign-off' },
] as const;

export type PlannedSignoffChoice =
    | 'tour_of_duty'
    | 'existing_plan'
    | 'manual_override';

export const TOUR_STATUS_SEVERITY_BAR: Record<string, string> = {
    normal: 'bg-emerald-500/70',
    info: 'bg-sky-500/70',
    warning: 'bg-amber-500/70',
    critical: 'bg-red-500/70',
};
