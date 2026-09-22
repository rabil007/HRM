import { CalendarDays, ClipboardList, Clock3, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { LeaveBalanceReportProps } from './types';

function formatDays(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function SummaryCard({
    label,
    value,
    hint,
    icon,
    valueClassName,
}: {
    label: string;
    value: string;
    hint: string;
    icon: ReactNode;
    valueClassName?: string;
}) {
    return (
        <Card className="h-full">
            <CardContent className="flex h-full flex-col gap-3 p-4">
                <div className="flex items-start justify-between gap-3">
                    <p className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                        {label}
                    </p>
                    <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-xl bg-muted/60 text-muted-foreground">
                        {icon}
                    </span>
                </div>
                <div className="mt-auto space-y-1">
                    <p
                        className={cn(
                            'text-2xl font-bold tracking-tight tabular-nums',
                            valueClassName,
                        )}
                    >
                        {value}
                    </p>
                    <p className="text-[11px] text-muted-foreground">{hint}</p>
                </div>
            </CardContent>
        </Card>
    );
}

export function LeaveBalanceReportSummaryCards({
    summary,
}: {
    summary: LeaveBalanceReportProps['summary'];
}) {
    return (
        <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <SummaryCard
                label="Employees"
                value={summary.employees.toLocaleString()}
                hint="With balances in this view"
                icon={<Users className="size-4" />}
            />
            <SummaryCard
                label="Balance rows"
                value={summary.balance_rows.toLocaleString()}
                hint="Employee × leave type × year"
                icon={<ClipboardList className="size-4" />}
            />
            <SummaryCard
                label="Used days"
                value={formatDays(summary.used_days)}
                hint="Taken from stored balances"
                icon={<CalendarDays className="size-4" />}
                valueClassName="text-emerald-600 dark:text-emerald-400"
            />
            <SummaryCard
                label="Pending days"
                value={formatDays(summary.pending_days)}
                hint="Awaiting leave decisions"
                icon={<Clock3 className="size-4" />}
                valueClassName="text-amber-600 dark:text-amber-400"
            />
        </div>
    );
}
