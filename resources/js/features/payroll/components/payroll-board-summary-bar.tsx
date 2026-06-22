import { Building2, Users } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { PayrollCategoryBadge } from './payroll-category-badge';
import { PayrollPeriodProgress } from './payroll-period-progress';
import type { PayrollBoardSummary, PayrollPeriod } from '../types';

export function PayrollBoardSummaryBar({
    period,
    summary,
}: {
    period: PayrollPeriod;
    summary: PayrollBoardSummary;
}) {
    return (
        <div className="mb-6 grid gap-4 md:grid-cols-3">
            <Card className="glass-card border-border/60 dark:border-white/10 md:col-span-2">
                <CardContent className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <PayrollCategoryBadge category={period.payroll_category} />
                            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                {period.supports_timesheets ? 'Timesheet entry' : 'Attendance payroll'}
                            </span>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {period.supports_timesheets
                                ? 'Track standby, onsite days, allowances, and adjustments for each crew member.'
                                : 'Office employees on this run will be calculated from attendance records in a later phase.'}
                        </p>
                    </div>
                    {period.supports_timesheets ? (
                        <div className="min-w-[220px] space-y-2">
                            <div className="flex items-center justify-between text-xs font-semibold">
                                <span className="text-muted-foreground">Completion</span>
                                <span>
                                    {summary.filled_count}/{summary.employee_count} · {summary.progress_percent}%
                                </span>
                            </div>
                            <PayrollPeriodProgress value={summary.progress_percent} />
                        </div>
                    ) : null}
                </CardContent>
            </Card>

            <Card className="glass-card border-border/60 dark:border-white/10">
                <CardContent className="space-y-4 p-5">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-border/60 bg-muted/30 dark:border-white/10 dark:bg-white/5">
                            {period.supports_timesheets ? (
                                <Users className="h-5 w-5 text-primary" />
                            ) : (
                                <Building2 className="h-5 w-5 text-violet-500" />
                            )}
                        </div>
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground/70">
                                Employees
                            </p>
                            <p className="text-2xl font-extrabold tracking-tight">
                                {summary.employee_count.toLocaleString()}
                            </p>
                        </div>
                    </div>
                    {period.supports_timesheets ? (
                        <p className="text-xs text-muted-foreground">
                            {summary.filled_count} timesheet{summary.filled_count === 1 ? '' : 's'} entered
                        </p>
                    ) : (
                        <p className="text-xs text-muted-foreground">Active office contracts on this run</p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}
