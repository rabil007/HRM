import { Anchor, Building2, ClipboardList, Receipt } from 'lucide-react';
import type { ElementType } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { PayrollHubSummary } from '../types';

function SummaryCard({
    title,
    value,
    hint,
    icon: Icon,
    iconClassName,
    accentClassName,
}: {
    title: string;
    value: string;
    hint: string;
    icon: ElementType;
    iconClassName: string;
    accentClassName?: string;
}) {
    return (
        <Card
            className={cn(
                'glass-card overflow-hidden border-border/60 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg dark:border-white/10',
                accentClassName,
            )}
        >
            <CardContent className="flex items-start gap-4 p-5">
                <div
                    className={cn(
                        'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border',
                        iconClassName,
                    )}
                >
                    <Icon className="h-5 w-5" />
                </div>
                <div className="min-w-0">
                    <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground/70">
                        {title}
                    </p>
                    <p className="mt-1 text-2xl font-extrabold tracking-tight">{value}</p>
                    <p className="mt-1 text-xs text-muted-foreground/80">{hint}</p>
                </div>
            </CardContent>
        </Card>
    );
}

export function PayrollSummaryCards({ summary }: { summary: PayrollHubSummary }) {
    return (
        <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <SummaryCard
                title="Pay runs"
                value={summary.total_periods.toLocaleString()}
                hint="All payroll periods"
                icon={Receipt}
                iconClassName="border-primary/20 bg-primary/10 text-primary"
            />
            <SummaryCard
                title="Draft"
                value={summary.draft_periods.toLocaleString()}
                hint="Runs still open for entry"
                icon={ClipboardList}
                iconClassName="border-amber-500/20 bg-amber-500/10 text-amber-600 dark:text-amber-300"
                accentClassName="hover:border-amber-500/25"
            />
            <SummaryCard
                title="Crew"
                value={summary.crew_periods.toLocaleString()}
                hint={
                    summary.incomplete_crew_runs > 0
                        ? `${summary.incomplete_crew_runs} run${summary.incomplete_crew_runs === 1 ? '' : 's'} need timesheets`
                        : 'Timesheet-based payroll'
                }
                icon={Anchor}
                iconClassName="border-sky-500/20 bg-sky-500/10 text-sky-600 dark:text-sky-300"
                accentClassName="hover:border-sky-500/25"
            />
            <SummaryCard
                title="Office"
                value={summary.office_periods.toLocaleString()}
                hint="Attendance-based payroll"
                icon={Building2}
                iconClassName="border-violet-500/20 bg-violet-500/10 text-violet-600 dark:text-violet-300"
                accentClassName="hover:border-violet-500/25"
            />
        </div>
    );
}
