import {
    Clock,
    MinusCircle,
    PlusCircle,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-react';
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
                'relative overflow-hidden glass-card border-border/60 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl dark:border-white/10',
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
                    <p className="text-[11px] font-bold tracking-[0.16em] text-muted-foreground/70 uppercase">
                        {title}
                    </p>
                    <p className="mt-1 text-2xl font-extrabold tracking-tight tabular-nums">
                        {value}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground/80">
                        {hint}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

export function PayrollRecordsSummaryCards({
    summary,
}: {
    summary: PayrollRecordsSummary;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <SummaryCard
                title="Total Employees"
                value={summary.employee_count.toLocaleString()}
                hint="Included in this pay run"
                icon={Users}
                iconClassName="border-primary/20 bg-primary/10 text-primary"
            />
            <SummaryCard
                title="Total Additions"
                value={formatTimesheetAmount(summary.total_additions)}
                hint="Bonuses and other salary inputs"
                icon={PlusCircle}
                iconClassName="border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"
                accentClassName="hover:border-emerald-500/25"
            />
            <SummaryCard
                title="Total Deductions"
                value={formatTimesheetAmount(summary.total_deductions)}
                hint="Late, loan, and other deductions"
                icon={MinusCircle}
                iconClassName="border-amber-500/20 bg-amber-500/10 text-amber-600 dark:text-amber-300"
                accentClassName="hover:border-amber-500/25"
            />
            <SummaryCard
                title="Total Overtime"
                value={formatTimesheetAmount(summary.total_overtime_pay)}
                hint={`${Number(summary.total_overtime_hours).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })} hours · salary ÷ 365 × 1.25`}
                icon={Clock}
                iconClassName="border-orange-500/20 bg-orange-500/10 text-orange-600 dark:text-orange-300"
                accentClassName="hover:border-orange-500/25"
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
                hint="Combined net payable"
                icon={Wallet}
                iconClassName="border-violet-500/20 bg-violet-500/10 text-violet-600 dark:text-violet-300"
                accentClassName="hover:border-violet-500/25"
            />
        </div>
    );
}
