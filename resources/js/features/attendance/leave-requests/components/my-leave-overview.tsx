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

const TILE =
    'flex min-h-[5.25rem] min-w-[7.25rem] flex-1 basis-0 flex-col justify-between rounded-xl border border-border/60 bg-card/60 px-3 py-2.5 text-left transition-colors dark:border-white/8 dark:bg-white/[0.03]';

function formatDays(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

const STATUS_ITEMS: {
    value: LeaveRequestStatus;
    label: string;
    countKey: Exclude<keyof LeaveRequestStatusCounts, 'all'>;
    icon: LucideIcon;
    accent: string;
}[] = [
    {
        value: 'pending',
        label: 'Pending',
        countKey: 'pending',
        icon: Clock,
        accent: '#f59e0b',
    },
    {
        value: 'approved',
        label: 'Approved',
        countKey: 'approved',
        icon: CheckCircle2,
        accent: '#10b981',
    },
    {
        value: 'rejected',
        label: 'Rejected',
        countKey: 'rejected',
        icon: XCircle,
        accent: '#ef4444',
    },
    {
        value: 'cancelled',
        label: 'Cancelled',
        countKey: 'cancelled',
        icon: Ban,
        accent: '#94a3b8',
    },
];

export function MyLeaveOverview({
    balances,
    year,
    showBalances,
    counts,
    activeStatus,
    onSelectStatus,
}: {
    balances: LeaveTypeYearBalance[];
    year: number | null;
    showBalances: boolean;
    counts: LeaveRequestStatusCounts;
    activeStatus: string;
    onSelectStatus: (status: '' | LeaveRequestStatus) => void;
}) {
    const hasBalances = showBalances && balances.length > 0;

    return (
        <section className="mb-6 space-y-2" aria-label="Leave overview">
            <div className="flex items-baseline gap-2">
                <h2 className="text-sm font-semibold tracking-tight text-foreground">
                    Overview
                </h2>
                {hasBalances && year !== null ? (
                    <span className="text-xs text-muted-foreground">
                        {year}
                    </span>
                ) : null}
            </div>

            <div className="flex gap-2 overflow-x-auto pb-0.5">
                {hasBalances
                    ? balances.map((balance) => {
                          const accent = balance.color ?? FALLBACK_COLOR;

                          return (
                              <div
                                  key={`balance-${balance.id}`}
                                  className={cn(TILE, 'min-w-[8.5rem]')}
                                  style={{
                                      borderLeftColor: accent,
                                      borderLeftWidth: 3,
                                  }}
                              >
                                  <div className="flex items-center justify-between gap-2">
                                      <p className="truncate text-xs font-semibold text-foreground">
                                          {balance.name}
                                      </p>
                                      <span className="shrink-0 text-[10px] font-bold tracking-wider text-muted-foreground uppercase">
                                          {balance.code}
                                      </span>
                                  </div>
                                  <div>
                                      <p
                                          className="text-xl font-bold tracking-tight tabular-nums"
                                          style={{ color: accent }}
                                      >
                                          {formatDays(balance.remaining_days)}
                                          <span className="ml-1 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                              left
                                          </span>
                                      </p>
                                      <p className="mt-0.5 text-[11px] text-muted-foreground tabular-nums">
                                          {formatDays(
                                              balance.total_available_days,
                                          )}{' '}
                                          avail ·{' '}
                                          {formatDays(balance.used_days)} used ·{' '}
                                          {formatDays(balance.pending_days)}{' '}
                                          pend
                                      </p>
                                      {balance.carried_days > 0 ? (
                                          <p className="mt-0.5 text-[10px] text-muted-foreground/80 tabular-nums">
                                              {formatDays(
                                                  balance.base_entitlement_days,
                                              )}
                                              +
                                              {formatDays(balance.carried_days)}{' '}
                                              carried
                                          </p>
                                      ) : null}
                                  </div>
                              </div>
                          );
                      })
                    : null}

                {hasBalances ? (
                    <div
                        className="mx-0.5 w-px shrink-0 self-stretch bg-border/80 dark:bg-white/10"
                        aria-hidden
                    />
                ) : null}

                {STATUS_ITEMS.map((item) => {
                    const isActive = activeStatus === item.value;
                    const Icon = item.icon;
                    const value = counts[item.countKey];

                    return (
                        <button
                            key={`status-${item.countKey}`}
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
                                TILE,
                                'min-w-[6.75rem] focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                isActive && 'bg-card dark:bg-white/[0.06]',
                            )}
                            style={{
                                borderLeftColor: item.accent,
                                borderLeftWidth: 3,
                                ...(isActive
                                    ? {
                                          boxShadow: `inset 0 0 0 1px ${item.accent}66`,
                                      }
                                    : {}),
                            }}
                        >
                            <div className="flex items-center justify-between gap-2">
                                <p className="truncate text-xs font-semibold text-foreground">
                                    {item.label}
                                </p>
                                <Icon
                                    className="size-3.5 shrink-0 opacity-70"
                                    style={{ color: item.accent }}
                                    aria-hidden
                                />
                            </div>
                            <div>
                                <p
                                    className="text-xl font-bold tracking-tight tabular-nums"
                                    style={{ color: item.accent }}
                                >
                                    {value.toLocaleString()}
                                </p>
                                <p className="mt-0.5 text-[11px] text-muted-foreground">
                                    requests
                                </p>
                            </div>
                        </button>
                    );
                })}
            </div>
        </section>
    );
}
