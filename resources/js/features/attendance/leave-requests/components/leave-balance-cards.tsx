import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
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
        <section className="mb-6 space-y-3" aria-label="Leave balances">
            <div>
                <h2 className="text-sm font-semibold tracking-tight text-foreground">
                    Leave balances
                </h2>
                {year !== null ? (
                    <p className="text-xs text-muted-foreground">
                        Allocations for {year}
                    </p>
                ) : null}
            </div>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {balances.map((balance) => {
                    const accent = balance.color ?? FALLBACK_COLOR;

                    return (
                        <Card
                            key={balance.id}
                            className="overflow-hidden glass-card border-border/60"
                            style={{
                                borderTopColor: accent,
                                borderTopWidth: 3,
                            }}
                        >
                            <CardContent className="space-y-3 p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold text-foreground">
                                            {balance.name}
                                        </p>
                                        <p className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                                            {balance.code}
                                        </p>
                                    </div>
                                    <span
                                        className="mt-1 size-2.5 shrink-0 rounded-full ring-2 ring-white/20"
                                        style={{ backgroundColor: accent }}
                                        aria-hidden
                                    />
                                </div>

                                <div>
                                    <p
                                        className={cn(
                                            'text-3xl font-bold tracking-tight tabular-nums',
                                        )}
                                        style={{ color: accent }}
                                    >
                                        {formatDays(balance.remaining_days)}
                                    </p>
                                    <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                        Remaining
                                    </p>
                                </div>

                                <dl className="space-y-1 text-xs text-muted-foreground">
                                    <div className="flex items-baseline justify-between gap-2">
                                        <dt>Available</dt>
                                        <dd className="font-semibold text-foreground tabular-nums">
                                            {formatDays(
                                                balance.total_available_days,
                                            )}
                                        </dd>
                                    </div>
                                    {balance.carried_days > 0 ? (
                                        <p className="text-[11px] text-muted-foreground/80 tabular-nums">
                                            {formatDays(
                                                balance.base_entitlement_days,
                                            )}{' '}
                                            entitlement +{' '}
                                            {formatDays(balance.carried_days)}{' '}
                                            carried
                                        </p>
                                    ) : null}
                                    <div className="flex items-baseline justify-between gap-2">
                                        <dt>Used</dt>
                                        <dd className="font-medium tabular-nums">
                                            {formatDays(balance.used_days)}
                                        </dd>
                                    </div>
                                    <div className="flex items-baseline justify-between gap-2">
                                        <dt>Pending</dt>
                                        <dd className="font-medium tabular-nums">
                                            {formatDays(balance.pending_days)}
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </section>
    );
}
