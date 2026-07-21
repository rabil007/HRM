import { Link } from '@inertiajs/react';
import { CalendarDays, ChevronRight, Users } from 'lucide-react';
import { show } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { PayrollPeriodListItem } from '../types';
import { getPeriodProgressPercent } from '../types';
import { PayrollCategoryBadge } from './payroll-category-badge';
import { PayrollPeriodProgress } from './payroll-period-progress';
import { PayrollPeriodStatusBadge } from './payroll-period-status-badge';

export function PayrollPeriodCard({
    period,
    canOpen,
    className,
}: {
    period: PayrollPeriodListItem;
    canOpen: boolean;
    className?: string;
}) {
    const progress = getPeriodProgressPercent(period);

    return (
        <Card
            className={cn(
                'group relative overflow-hidden glass-card transition-all duration-300 hover:-translate-y-1 hover:shadow-xl dark:border-white/10',
                className,
            )}
        >
            <div className="absolute inset-0 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:24px_24px] opacity-20"></div>
            {canOpen ? (
                <Link
                    href={show.url(period.id)}
                    className="absolute inset-0 z-10"
                    aria-label={`Open ${period.name}`}
                />
            ) : null}

            <CardHeader className="relative z-10 space-y-4 pb-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <PayrollCategoryBadge
                                category={period.payroll_category}
                            />
                            <PayrollPeriodStatusBadge
                                status={period.status}
                                label={period.status_label}
                            />
                        </div>
                        <CardTitle className="line-clamp-2 text-lg font-extrabold tracking-tight">
                            {period.name}
                        </CardTitle>
                    </div>
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-border/60 bg-gradient-to-br from-primary/5 to-primary/10 shadow-inner dark:border-white/10">
                        <CalendarDays className="h-6 w-6 text-primary/70 drop-shadow-sm" />
                    </div>
                </div>
            </CardHeader>

            <CardContent className="relative z-10 space-y-4">
                <div className="grid grid-cols-2 gap-3 text-sm">
                    <div className="rounded-xl border border-border/50 bg-muted/20 px-3 py-2.5 dark:border-white/10 dark:bg-white/5">
                        <p className="text-[10px] font-bold tracking-wider text-muted-foreground/70 uppercase">
                            Period
                        </p>
                        <p className="mt-1 font-semibold">
                            {formatDisplayDate(period.start_date)} —{' '}
                            {formatDisplayDate(period.end_date)}
                        </p>
                    </div>
                    <div className="rounded-xl border border-border/50 bg-muted/20 px-3 py-2.5 dark:border-white/10 dark:bg-white/5">
                        <p className="text-[10px] font-bold tracking-wider text-muted-foreground/70 uppercase">
                            Payment
                        </p>
                        <p className="mt-1 font-semibold">
                            {period.payment_date
                                ? formatDisplayDate(period.payment_date)
                                : 'Pending'}
                        </p>
                    </div>
                    <div className="rounded-xl border border-border/50 bg-muted/20 px-3 py-2.5 dark:border-white/10 dark:bg-white/5">
                        <p className="text-[10px] font-bold tracking-wider text-muted-foreground/70 uppercase">
                            Generated
                        </p>
                        <p className="mt-1 font-semibold">
                            {period.generated_at
                                ? formatDisplayDate(period.generated_at)
                                : 'Not generated'}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Users className="h-4 w-4" />
                    <span>
                        {period.employee_count}{' '}
                        {period.payroll_category_label.toLowerCase()} employee
                        {period.employee_count === 1 ? '' : 's'}
                    </span>
                </div>

                {period.supports_timesheets ? (
                    <div className="space-y-2">
                        <div className="flex items-center justify-between text-xs font-semibold">
                            <span className="text-muted-foreground">
                                Timesheets
                            </span>
                            <span>
                                {period.timesheets_progress_label} · {progress}%
                            </span>
                        </div>
                        <PayrollPeriodProgress value={progress} />
                    </div>
                ) : null}

                {canOpen ? (
                    <Button
                        variant="secondary"
                        size="sm"
                        className="relative z-20 w-full rounded-xl glass-card"
                        asChild
                    >
                        <Link href={show.url(period.id)}>
                            Open pay run
                            <ChevronRight className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                ) : null}
            </CardContent>
        </Card>
    );
}
