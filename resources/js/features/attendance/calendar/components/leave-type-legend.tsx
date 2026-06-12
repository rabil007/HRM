import { Sparkles } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { CalendarLeaveType } from '../types';

const FALLBACK_COLOR = '#8b5cf6';

function formatDays(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

function LeaveTypeBalance({ leaveType, year }: { leaveType: CalendarLeaveType; year: number }) {
    if (leaveType.remaining_days === null || leaveType.entitled_days === null) {
        return null;
    }

    return (
        <div className="mt-1 space-y-0.5 text-[11px] text-muted-foreground">
            <div>
                <span className="font-bold text-foreground tabular-nums">{formatDays(leaveType.remaining_days)}</span>
                <span> of </span>
                <span className="font-semibold tabular-nums">{formatDays(leaveType.entitled_days)}</span>
                <span> days left</span>
            </div>
            {(leaveType.used_days ?? 0) > 0 || (leaveType.pending_days ?? 0) > 0 ? (
                <div className="tabular-nums">
                    {formatDays(leaveType.used_days ?? 0)} used
                    {(leaveType.pending_days ?? 0) > 0 ? ` · ${formatDays(leaveType.pending_days ?? 0)} pending` : ''}
                    <span className="text-muted-foreground/60"> in {year}</span>
                </div>
            ) : null}
        </div>
    );
}

export function LeaveTypeLegend({
    leaveTypes,
    year,
    showBalance,
}: {
    leaveTypes: CalendarLeaveType[];
    year: number;
    showBalance: boolean;
}) {
    return (
        <Card className="glass-card overflow-hidden border-border/60 bg-card/80 dark:border-white/6 dark:bg-white/4">
            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/6">
                <div className="flex items-center gap-2">
                    <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Sparkles className="size-4" />
                    </div>
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight">Legend</CardTitle>
                        <p className="text-xs font-medium text-muted-foreground/75">
                            {showBalance ? `Leave balance in ${year}` : `Approved leave in ${year}`}
                        </p>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-2 pt-4">
                {leaveTypes.map((leaveType) => (
                    <div
                        key={leaveType.id}
                        className="rounded-xl border border-border/60 bg-muted/20 px-3 py-2.5 transition-colors hover:bg-muted/40 dark:border-white/6 dark:bg-white/3 dark:hover:bg-white/6"
                    >
                        <div className="flex items-start gap-3">
                            <span
                                className="mt-1 size-3.5 shrink-0 rounded-full ring-2 ring-white/20"
                                style={{ backgroundColor: leaveType.color ?? FALLBACK_COLOR }}
                            />
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center justify-between gap-2">
                                    <div className="truncate text-sm font-semibold">{leaveType.name}</div>
                                    <Badge
                                        variant="secondary"
                                        className="shrink-0 bg-muted/50 text-[10px] font-bold uppercase tracking-wider dark:bg-white/8"
                                    >
                                        {leaveType.code}
                                    </Badge>
                                </div>
                                {showBalance ? <LeaveTypeBalance leaveType={leaveType} year={year} /> : null}
                            </div>
                        </div>
                    </div>
                ))}
                {leaveTypes.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border/60 px-3 py-6 text-center text-sm text-muted-foreground/80 dark:border-white/8">
                        {showBalance ? 'No active leave types found.' : 'No approved leave types in this year.'}
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}
