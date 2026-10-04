import type {
    CrewReadinessFocus,
    CrewReadinessSummary,
} from '@/features/organization/crew-readiness/types';

export const CREW_READINESS_RESET_QUERY: Record<string, string | null> = {
    search: null,
    vessel_id: null,
    position_id: null,
    readiness_status: 'all',
    source: 'all',
    window: '30',
    focus: null,
    page: null,
};

export interface CrewReadinessQuickViewItem {
    key: CrewReadinessFocus;
    label: string;
    tone: string;
    getValue: (summary: CrewReadinessSummary) => number;
}

export const CREW_READINESS_QUICK_VIEWS: CrewReadinessQuickViewItem[] = [
    {
        key: '',
        label: 'Upcoming Crew',
        tone: 'text-foreground',
        getValue: (s) => s.upcoming_crew,
    },
    {
        key: 'ready',
        label: 'Ready',
        tone: 'text-emerald-700 dark:text-emerald-300',
        getValue: (s) => s.ready,
    },
    {
        key: 'attention',
        label: 'Needs Attention',
        tone: 'text-amber-700 dark:text-amber-300',
        getValue: (s) => s.attention,
    },
    {
        key: 'not_ready',
        label: 'Not Ready',
        tone: 'text-rose-700 dark:text-rose-300',
        getValue: (s) => s.not_ready,
    },
    {
        key: 'joining_7',
        label: 'Joining in 7 Days',
        tone: 'text-sky-700 dark:text-sky-300',
        getValue: (s) => s.joining_7,
    },
    {
        key: 'no_checks',
        label: 'No Checks Configured',
        tone: 'text-muted-foreground',
        getValue: (s) => s.no_checks,
    },
];

export function formatJoinTimeline(
    daysUntilJoin: number | null,
    isOverdue: boolean,
): { label: string; tone: string } | null {
    if (daysUntilJoin === null) {
        return null;
    }

    if (isOverdue) {
        const absDays = Math.abs(daysUntilJoin);

        return {
            label: absDays === 1 ? '1 day overdue' : `${absDays} days overdue`,
            tone: 'text-rose-600 dark:text-rose-400 font-semibold',
        };
    }

    if (daysUntilJoin === 0) {
        return {
            label: 'Joining today',
            tone: 'text-amber-600 dark:text-amber-400 font-semibold',
        };
    }

    if (daysUntilJoin === 1) {
        return {
            label: 'Joining tomorrow',
            tone: 'text-amber-600 dark:text-amber-400 font-medium',
        };
    }

    if (daysUntilJoin <= 7) {
        return {
            label: `In ${daysUntilJoin} days`,
            tone: 'text-sky-600 dark:text-sky-400 font-medium',
        };
    }

    return {
        label: `In ${daysUntilJoin} days`,
        tone: 'text-muted-foreground',
    };
}
