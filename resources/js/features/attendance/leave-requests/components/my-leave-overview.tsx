import { Ban, CheckCircle2, Clock, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import type {
    LeaveRequestStatus,
    LeaveRequestStatusCounts,
    LeaveTypeYearBalance,
} from '../types';

export type { LeaveRequestStatusCounts };

const FALLBACK_COLOR = '#64748b';

function formatDays(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

const STATUS_ITEMS: {
    value: LeaveRequestStatus;
    label: string;
    countKey: Exclude<keyof LeaveRequestStatusCounts, 'all'>;
    icon: LucideIcon;
    activeClass: string;
}[] = [
    {
        value: 'pending',
        label: 'Pending',
        countKey: 'pending',
        icon: Clock,
        activeClass:
            'border-amber-500/40 bg-amber-500/15 text-amber-700 dark:text-amber-300',
    },
    {
        value: 'approved',
        label: 'Approved',
        countKey: 'approved',
        icon: CheckCircle2,
        activeClass:
            'border-emerald-500/40 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    },
    {
        value: 'rejected',
        label: 'Rejected',
        countKey: 'rejected',
        icon: XCircle,
        activeClass:
            'border-red-500/40 bg-red-500/15 text-red-700 dark:text-red-300',
    },
    {
        value: 'cancelled',
        label: 'Cancelled',
        countKey: 'cancelled',
        icon: Ban,
        activeClass: 'border-muted-foreground/40 bg-muted text-foreground',
    },
];

export function MyLeaveStatusFilters({
    counts,
    activeStatus,
    onSelectStatus,
}: {
    counts: LeaveRequestStatusCounts;
    activeStatus: string;
    onSelectStatus: (status: '' | LeaveRequestStatus) => void;
}) {
    return (
        <div
            className="flex flex-wrap items-center gap-1.5"
            role="group"
            aria-label="Filter by request status"
        >
            {STATUS_ITEMS.map((item) => {
                const isActive = activeStatus === item.value;
                const Icon = item.icon;
                const value = counts[item.countKey];

                return (
                    <button
                        key={item.countKey}
                        type="button"
                        onClick={() =>
                            onSelectStatus(isActive ? '' : item.value)
                        }
                        aria-pressed={isActive}
                        aria-label={
                            isActive
                                ? `Clear ${item.label.toLowerCase()} filter and show all leave requests`
                                : `Show ${item.label.toLowerCase()} leave requests`
                        }
                        className={cn(
                            'inline-flex h-9 items-center gap-1.5 rounded-lg border border-transparent px-2.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            isActive && item.activeClass,
                        )}
                    >
                        <Icon
                            className="size-3.5 shrink-0 opacity-80"
                            aria-hidden
                        />
                        <span>{item.label}</span>
                        <span className="tabular-nums opacity-80">
                            {value.toLocaleString()}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

export function MyLeaveOverview({
    balances,
    year,
    showBalances,
}: {
    balances: LeaveTypeYearBalance[];
    year: number | null;
    showBalances: boolean;
}) {
    if (!showBalances || balances.length === 0) {
        return null;
    }

    return (
        <section className="mb-5" aria-label="Leave balances">
            <div className="mb-2 flex items-baseline gap-2">
                <h2 className="text-sm font-semibold tracking-tight text-foreground">
                    Leave balances
                </h2>
                {year !== null ? (
                    <span className="text-xs text-muted-foreground">
                        {year}
                    </span>
                ) : null}
            </div>

            <div className="flex flex-col gap-2 sm:flex-row">
                {balances.map((balance) => {
                    const accent = balance.color ?? FALLBACK_COLOR;

                    return (
                        <div
                            key={balance.id}
                            className="relative min-w-0 flex-1 overflow-hidden rounded-xl border glass-card border-border/60 bg-card/80 px-3 py-2.5 dark:border-white/8"
                        >
                            <div
                                className="pointer-events-none absolute inset-y-0 left-0 w-0.5"
                                style={{ backgroundColor: accent }}
                                aria-hidden
                            />

                            <div className="flex items-center justify-between gap-2 pl-1">
                                <div className="min-w-0">
                                    <p className="truncate text-xs font-semibold text-foreground">
                                        {balance.name}
                                    </p>
                                    <p className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                        {balance.code}
                                    </p>
                                </div>
                                <p
                                    className="shrink-0 text-xl font-bold tracking-tight tabular-nums"
                                    style={{ color: accent }}
                                >
                                    {formatDays(balance.remaining_days)}
                                    <span className="ml-1 text-[9px] font-semibold tracking-wide text-muted-foreground uppercase">
                                        left
                                    </span>
                                </p>
                            </div>

                            <p className="mt-1.5 pl-1 text-[11px] text-muted-foreground tabular-nums">
                                {formatDays(balance.total_available_days)} avail
                                <span className="mx-1 opacity-40">·</span>
                                {formatDays(balance.total_used_days)} used
                                <span className="mx-1 opacity-40">·</span>
                                {formatDays(balance.pending_days)} pend
                                {balance.carried_days > 0 ? (
                                    <>
                                        <span className="mx-1 opacity-40">
                                            ·
                                        </span>
                                        {formatDays(
                                            balance.base_entitlement_days,
                                        )}
                                        +{formatDays(balance.carried_days)}{' '}
                                        carry
                                    </>
                                ) : null}
                            </p>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
