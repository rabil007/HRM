import { Building2, Users } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { formatDisplayDate } from '@/lib/format-date';
import type { PayrollBoardSummary, PayrollPeriod } from '../types';
import { PayrollCategoryBadge } from './payroll-category-badge';
import { PayrollPeriodProgress } from './payroll-period-progress';

export function PayrollBoardSummaryBar({
    period,
    summary,
}: {
    period: PayrollPeriod;
    summary: PayrollBoardSummary;
}) {
    return (
        <div className="mb-6 grid gap-4 md:grid-cols-3">
            <Card className="glass-card relative overflow-hidden border-border/60 bg-gradient-to-br from-background via-background/90 to-primary/5 hover:to-primary/10 transition-colors duration-500 dark:border-white/10 md:col-span-2">
                <div className="absolute inset-0 bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:24px_24px] opacity-30"></div>
                <CardContent className="relative z-10 flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
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

            <Card className="glass-card relative overflow-hidden border-border/60 bg-gradient-to-br from-background via-background/90 to-primary/5 hover:to-primary/10 transition-colors duration-500 dark:border-white/10">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-primary/10 via-transparent to-transparent opacity-50"></div>
                <CardContent className="relative z-10 space-y-4 p-5">
                    <div className="flex items-center gap-3">
                        <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-primary/10 to-primary/20 shadow-inner border border-primary/10">
                            {period.supports_timesheets ? (
                                <Users className="h-6 w-6 text-primary drop-shadow-sm" />
                            ) : (
                                <Building2 className="h-6 w-6 text-violet-500 drop-shadow-sm" />
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
                    {period.approved_at ? (
                        <p className="text-xs text-muted-foreground">
                            Approved by {period.approver?.name ?? '—'} on{' '}
                            {formatDisplayDate(period.approved_at)}
                        </p>
                    ) : null}
                    {period.payroll_records_count > 0 ? (
                        <p className="text-xs text-muted-foreground">
                            {period.payroll_records_count} payroll record
                            {period.payroll_records_count === 1 ? '' : 's'} generated
                        </p>
                    ) : null}
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
