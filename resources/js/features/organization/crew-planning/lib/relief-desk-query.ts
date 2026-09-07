import type { ServerQueryParams } from '@/hooks/use-server-pagination-filters';
import type { ReliefDeskFocus, ReliefDeskSummary } from '../types';

export const RELIEF_DESK_RESET_QUERY: ServerQueryParams = {
    view: 'relief',
    search: '',
    vessel_id: null,
    rank_id: null,
    client_id: null,
    relief_status: '',
    relief_risk: '',
    planned_signoff_from: '',
    planned_signoff_to: '',
    horizon: '30',
    focus: '',
    page: 1,
};

export const RELIEF_DESK_QUICK_VIEWS: Array<{
    key: Exclude<ReliefDeskFocus, ''>;
    label: string;
    getValue: (summary: ReliefDeskSummary) => number;
    tone: string;
}> = [
    {
        key: 'needs_relief',
        label: 'Needs Relief',
        getValue: (summary) => summary.needs_relief,
        tone: 'text-red-600 dark:text-red-300',
    },
    {
        key: 'critical',
        label: 'Critical',
        getValue: (summary) => summary.critical,
        tone: 'text-red-600 dark:text-red-300',
    },
    {
        key: 'not_ready',
        label: 'Relief Not Ready',
        getValue: (summary) => summary.not_ready,
        tone: 'text-amber-600 dark:text-amber-300',
    },
    {
        key: 'signoff_14',
        label: 'Next 14 Days',
        getValue: (summary) => summary.signoff_14,
        tone: 'text-amber-700 dark:text-amber-200',
    },
    {
        key: 'ready',
        label: 'Ready',
        getValue: (summary) => summary.ready,
        tone: 'text-emerald-600 dark:text-emerald-300',
    },
    {
        key: 'overdue',
        label: 'Overdue',
        getValue: (summary) => summary.overdue,
        tone: 'text-red-700 dark:text-red-200',
    },
];
