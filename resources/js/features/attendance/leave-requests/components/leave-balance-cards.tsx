import type { LeaveTypeYearBalance } from '../types';

const FALLBACK_COLOR = '#64748b';

function formatDays(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

export function LeaveBalanceCards({
    balances,
    year,
}: {
    balances: LeaveTypeYearBalance[];
    year: number | null;
}) {
    if (balances.length === 0) {
        return null;
    }

    return (
        <div className="min-w-0 flex-1 space-y-2" aria-label="Leave balances">
            <div className="flex items-baseline gap-2">
                <h2 className="text-sm font-semibold tracking-tight text-foreground">
                    Leave balances
                </h2>
                {year !== null ? (
                    <span className="text-xs text-muted-foreground">
                        {year}
                    </span>
                ) : null}
            </div>

            <div className="flex gap-2 overflow-x-auto pb-0.5">
                {balances.map((balance) => {
                    const accent = balance.color ?? FALLBACK_COLOR;

                    return (
                        <div
                            key={balance.id}
                            className="min-w-[9.5rem] shrink-0 rounded-xl border border-border/60 bg-card/60 px-3 py-2.5 dark:border-white/8 dark:bg-white/[0.03]"
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
                            <p
                                className="mt-1 text-xl font-bold tracking-tight tabular-nums"
                                style={{ color: accent }}
                            >
                                {formatDays(balance.remaining_days)}
                                <span className="ml-1 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                    left
                                </span>
                            </p>
                            <p className="mt-0.5 text-[11px] text-muted-foreground tabular-nums">
                                {formatDays(balance.total_available_days)} avail
                                {' · '}
                                {formatDays(balance.used_days)} used
                                {' · '}
                                {formatDays(balance.pending_days)} pend
                            </p>
                            {balance.carried_days > 0 ? (
                                <p className="mt-0.5 text-[10px] text-muted-foreground/80 tabular-nums">
                                    {formatDays(balance.base_entitlement_days)}+
                                    {formatDays(balance.carried_days)} carried
                                </p>
                            ) : null}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
