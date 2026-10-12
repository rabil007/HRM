import type { CandidateStage } from '../types';

/** Quiet accents for column headers and card edges. */
export const STAGE_COLORS: Record<
    CandidateStage,
    { accent: string; badge: string }
> = {
    applied: {
        accent: 'bg-slate-400',
        badge: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
    },
    screening: {
        accent: 'bg-sky-500',
        badge: 'bg-sky-50 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    },
    interview: {
        accent: 'bg-indigo-500',
        badge: 'bg-indigo-50 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300',
    },
    offer_jol: {
        accent: 'bg-amber-500',
        badge: 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-300',
    },
    joining: {
        accent: 'bg-orange-500',
        badge: 'bg-orange-50 text-orange-800 dark:bg-orange-950 dark:text-orange-300',
    },
    joined: {
        accent: 'bg-emerald-500',
        badge: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    },
    rejected: {
        accent: 'bg-rose-400',
        badge: 'bg-rose-50 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
    },
};

export const STAGE_HINTS: Record<CandidateStage, string> = {
    applied: 'Advance to Screening',
    screening: 'Advance to Interview',
    interview: 'Select or reject',
    offer_jol: 'Offer → join prep → Confirm Joined',
    joining: 'Confirm joining',
    joined: 'Convert when ready',
    rejected: 'Reopen if needed',
};

export function getInitials(name: string): string {
    const parts = name.trim().split(/\s+/);

    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }

    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

export function daysInStageLabel(
    days: number | null | undefined,
): string | null {
    if (days === null || days === undefined) {
        return null;
    }

    if (days <= 0) {
        return 'Today';
    }

    if (days === 1) {
        return '1d waiting';
    }

    return `${days}d waiting`;
}
