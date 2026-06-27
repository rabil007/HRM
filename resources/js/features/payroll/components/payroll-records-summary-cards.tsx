import { TrendingUp, Users, Wallet } from 'lucide-react';
import type { ElementType } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { PayrollRecordsSummary } from '../types';
import { formatTimesheetAmount } from '../types';

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
                'glass-card relative overflow-hidden border-border/60 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl dark:border-white/10',
                accentClassName,
            )}
        >
            <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-primary/5 via-transparent to-transparent opacity-50" />
            <CardContent className="relative z-10 flex items-start gap-4 p-5">
                <div
                    className={cn(
                        'flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border shadow-inner',
                        iconClassName,
                    )}
                >
                    <Icon className="h-6 w-6 drop-shadow-sm" />
                </div>
                <div className="min-w-0">
                    <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground/70">
                        {title}
                    </p>
                    <p className="mt-1 text-2xl font-extrabold tabular-nums tracking-tight">{value}</p>
                    <p className="mt-1 text-xs text-muted-foreground/80">{hint}</p>
                </div>
            </CardContent>
        </Card>
    );
}

export function PayrollRecordsSummaryCards({ summary }: { summary: PayrollRecordsSummary }) {
    const netHint =
        Number(summary.total_deductions) > 0
            ? `Payable after ${formatTimesheetAmount(summary.total_deductions)} in deductions`
            : 'Payable after deductions';

    return (
        <div className="grid gap-4 sm:grid-cols-3">
            <SummaryCard
                title="Total Employees"
                value={summary.employee_count.toLocaleString()}
                hint="Included in this pay run"
                icon={Users}
                iconClassName="border-primary/20 bg-primary/10 text-primary"
            />
            <SummaryCard
                title="Total Gross"
                value={formatTimesheetAmount(summary.total_gross)}
                hint="Combined gross salary"
                icon={TrendingUp}
                iconClassName="border-sky-500/20 bg-sky-500/10 text-sky-600 dark:text-sky-300"
                accentClassName="hover:border-sky-500/25"
            />
            <SummaryCard
                title="Total Net"
                value={formatTimesheetAmount(summary.total_net)}
                hint={netHint}
                icon={Wallet}
                iconClassName="border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"
                accentClassName="hover:border-emerald-500/25"
            />
        </div>
    );
}
