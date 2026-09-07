import type { ReliefDeskRow } from '../types';

function formatDeskDate(value: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value.trim());

    if (!match) {
        return value;
    }

    const [, year, month, day] = match;

    return `${day}-${month}-${year}`;
}

export function reliefDeskSignoffSummary(row: ReliefDeskRow): string {
    if (row.missing_planned_signoff || row.planned_signoff_at === null) {
        return 'Missing Planned Sign-Off';
    }

    const date = formatDeskDate(row.planned_signoff_at);
    const days = row.days_until_signoff;

    if (days === null) {
        return date;
    }

    if (days < 0) {
        return `${date} · ${Math.abs(days)}d overdue`;
    }

    if (days === 0) {
        return `${date} · due today`;
    }

    return `${date} · ${days}d`;
}

export function reliefDeskDutySummary(row: ReliefDeskRow): string {
    const phase = row.current_phase_code?.toUpperCase() ?? 'P4';

    if (row.current_duty_day != null) {
        return `${phase} · Day ${row.current_duty_day}`;
    }

    return phase;
}

export type ReliefDeskMobileCardModel = {
    title: string;
    subtitle: string;
    signoff: string;
    relief: string;
    reliefPhase: string | null;
    readiness: string | null;
    risk: string;
    actionLabel: string | null;
    actionHref: string | null;
};

export function reliefDeskMobileCardModel(
    row: ReliefDeskRow,
): ReliefDeskMobileCardModel {
    const vesselRank = [row.vessel?.name, row.rank?.name]
        .filter((part): part is string => Boolean(part))
        .join(' · ');

    const reliefName = row.relief_employee?.name ?? null;
    const relief =
        row.relief_status === 'no_relief'
            ? 'No Relief'
            : (reliefName ?? row.relief_status_label);

    return {
        title: vesselRank || 'Unassigned vessel',
        subtitle: [
            row.employee?.name ?? 'Unassigned',
            reliefDeskDutySummary(row),
        ]
            .filter(Boolean)
            .join(' · '),
        signoff: reliefDeskSignoffSummary(row),
        relief,
        reliefPhase: row.relief_phase_label,
        readiness: row.mobilisation_readiness?.status_label ?? null,
        risk: row.relief_risk_label,
        actionLabel: row.recommended_action.href
            ? row.recommended_action.label
            : null,
        actionHref: row.recommended_action.href,
    };
}
