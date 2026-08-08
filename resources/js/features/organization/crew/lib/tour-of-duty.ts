import type { CrewAssignmentFormOptions } from '../types';

export type CrewRankTourOption = CrewAssignmentFormOptions['ranks'][number];

export function resolveJoinTourDays(
    rank: CrewRankTourOption | undefined,
): number | null {
    const days = rank?.max_tour_of_duty_days ?? rank?.resolved_tour_of_duty_days;

    return days != null && days > 0 ? days : null;
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
