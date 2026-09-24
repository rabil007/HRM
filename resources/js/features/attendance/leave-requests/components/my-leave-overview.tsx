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
        <section className="mb-6" aria-label="Leave balances">
            <div className="mb-3 flex items-baseline gap-2">
                <h2 className="text-sm font-semibold tracking-tight text-foreground">
                    Leave balances
                </h2>
                {year !== null ? (
                    <span className="text-xs text-muted-foreground">
                        {year}
                    </span>
                ) : null}
            </div>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {balances.map((balance) => {
                    const accent = balance.color ?? FALLBACK_COLOR;

                    return (
                        <div
                            key={balance.id}
                            className="relative overflow-hidden rounded-2xl border glass-card border-border/60 bg-card/80 p-4 dark:border-white/8"
                        >
                            <div
                                className="pointer-events-none absolute inset-x-0 top-0 h-1"
                                style={{ backgroundColor: accent }}
                                aria-hidden
                            />

                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-semibold text-foreground">
                                        {balance.name}
                                    </p>
                                    <p className="mt-0.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                                        {balance.code}
                                    </p>
                                </div>
                                <span
                                    className="mt-1 size-2.5 shrink-0 rounded-full"
                                    style={{ backgroundColor: accent }}
                                    aria-hidden
                                />
                            </div>

                            <p
                                className="mt-4 text-3xl font-bold tracking-tight tabular-nums"
                                style={{ color: accent }}
                            >
                                {formatDays(balance.remaining_days)}
                                <span className="ml-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    remaining
                                </span>
                            </p>

                            <dl className="mt-3 grid grid-cols-3 gap-2 border-t border-border/50 pt-3 dark:border-white/8">
                                <div>
                                    <dt className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                        Available
                                    </dt>
                                    <dd className="mt-0.5 text-sm font-semibold text-foreground tabular-nums">
                                        {formatDays(
                                            balance.total_available_days,
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                        Used
                                    </dt>
                                    <dd className="mt-0.5 text-sm font-semibold text-foreground tabular-nums">
                                        {formatDays(balance.used_days)}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                                        Pending
                                    </dt>
                                    <dd className="mt-0.5 text-sm font-semibold text-foreground tabular-nums">
                                        {formatDays(balance.pending_days)}
                                    </dd>
                                </div>
                            </dl>

                            {balance.carried_days > 0 ? (
                                <p className="mt-2 text-[11px] text-muted-foreground tabular-nums">
                                    {formatDays(balance.base_entitlement_days)}{' '}
                                    entitlement +{' '}
                                    {formatDays(balance.carried_days)} carried
                                </p>
                            ) : null}
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
