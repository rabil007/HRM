import { Ban, CheckCircle2, Clock, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { LEAVE_REQUEST_STATUS_SUMMARY_LABELS } from '../lib/leave-request-status-summary';
import type { LeaveRequestStatus } from '../types';

export type LeaveRequestStatusCounts = {
    all: number;
    pending: number;
    approved: number;
    rejected: number;
    cancelled: number;
};

export { LEAVE_REQUEST_STATUS_SUMMARY_LABELS };

type StatusFilterValue = '' | LeaveRequestStatus;

const SUMMARY_ITEMS: {
    value: Exclude<StatusFilterValue, ''>;
    label: (typeof LEAVE_REQUEST_STATUS_SUMMARY_LABELS)[number];
    countKey: Exclude<keyof LeaveRequestStatusCounts, 'all'>;
    icon: LucideIcon;
    idleClass: string;
    activeClass: string;
}[] = [
    {
        value: 'pending',
        label: 'Pending',
        countKey: 'pending',
        icon: Clock,
        idleClass:
            'border-amber-500/20 bg-amber-500/5 text-amber-700 hover:border-amber-500/40 dark:text-amber-400',
        activeClass:
            'border-amber-500/50 bg-amber-500/15 text-amber-700 ring-1 ring-amber-500/30 dark:text-amber-300',
    },
    {
        value: 'approved',
        label: 'Approved',
        countKey: 'approved',
        icon: CheckCircle2,
        idleClass:
            'border-emerald-500/20 bg-emerald-500/5 text-emerald-700 hover:border-emerald-500/40 dark:text-emerald-400',
        activeClass:
            'border-emerald-500/50 bg-emerald-500/15 text-emerald-700 ring-1 ring-emerald-500/30 dark:text-emerald-300',
    },
    {
        value: 'rejected',
        label: 'Rejected',
        countKey: 'rejected',
        icon: XCircle,
        idleClass:
            'border-red-500/20 bg-red-500/5 text-red-700 hover:border-red-500/40 dark:text-red-400',
        activeClass:
            'border-red-500/50 bg-red-500/15 text-red-700 ring-1 ring-red-500/30 dark:text-red-300',
    },
    {
        value: 'cancelled',
        label: 'Cancelled',
        countKey: 'cancelled',
        icon: Ban,
        idleClass:
            'border-border bg-muted/40 text-muted-foreground hover:border-muted-foreground/40',
        activeClass:
            'border-muted-foreground/50 bg-muted text-foreground ring-1 ring-muted-foreground/25',
    },
];

export function LeaveRequestSummaryCards({
    counts,
    activeStatus,
    onSelect,
}: {
    counts: LeaveRequestStatusCounts;
    activeStatus: string;
    onSelect: (status: StatusFilterValue) => void;
}) {
    return (
        <div
            className="flex shrink-0 flex-wrap items-center gap-1.5"
            role="group"
            aria-label="Filter by request status"
        >
            {SUMMARY_ITEMS.map((item) => {
                const isActive = activeStatus === item.value;
                const Icon = item.icon;
                const value = counts[item.countKey];

                return (
                    <button
                        key={item.countKey}
                        type="button"
                        onClick={() => onSelect(isActive ? '' : item.value)}
                        aria-pressed={isActive}
                        aria-label={
                            isActive
                                ? `Clear ${item.label.toLowerCase()} filter and show all leave requests`
                                : `Show ${item.label.toLowerCase()} leave requests`
                        }
                        className={cn(
                            'inline-flex h-9 items-center gap-1.5 rounded-lg border px-2.5 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            isActive ? item.activeClass : item.idleClass,
                        )}
                    >
                        <Icon
                            className="size-3.5 shrink-0 opacity-80"
                            aria-hidden
                        />
                        <span>{item.label}</span>
                        <span className="tabular-nums opacity-90">
                            {value.toLocaleString()}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
