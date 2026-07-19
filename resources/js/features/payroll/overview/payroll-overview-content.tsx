import { Link, usePage } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowUpRight,
    Award,
    BarChart3,
    Briefcase,
    CalendarDays,
    CheckCircle2,
    ChevronRight,
    CircleDollarSign,
    Clock,
    FileSpreadsheet,
    FileText,
    LayoutDashboard,
    Minus,
    PiggyBank,
    Plus,
    Ship,
    Sigma,
    Target,
    TrendingDown,
    TrendingUp,
    Users,
    Wallet,
    Zap,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { SalarySheetPayslipDialog } from '@/features/payroll/overview/salary-sheet-payslip-dialog';
import { cn } from '@/lib/utils';
import { index as payrollIndex } from '@/routes/payroll';
import { index as recordsIndex } from '@/routes/payroll/records';

/* ─────────────────────── types ─────────────────────── */

type AttentionItem = {
    title: string;
    subtitle: string;
    type: string;
    severity: string;
};

type MonthlyTrend = {
    month: string;
    total: number;
    count: number;
    avg: number;
    gross: number;
    deductions: number;
    overtime: number;
};

type MonthlyCategoryCost = {
    month: string;
    crew: number;
    office: number;
};

type TopEarner = {
    name: string;
    employee_no: string;
    department: string | null;
    net_salary: number;
    category: string;
};

type OverviewSummary = {
    draft_periods: number;
    processing_periods: number;
    approved_periods: number;
    paid_periods: number;
    total_employees_in_payroll: number;
    crew_employee_count: number;
    office_employee_count: number;
    last_paid_period_total: number | null;
    last_paid_period_name: string | null;
    last_paid_period_gross: number | null;
    last_paid_period_deductions: number | null;
    last_paid_period_avg_net: number | null;
    last_paid_period_employee_count: number | null;
    ytd_payroll_total: number;
    ytd_gross_total: number;
    ytd_deductions_total: number;
    ytd_overtime_total: number;
    total_paid_periods_all_time: number;
    payroll_efficiency_pct: number | null;
    mom_net_change_pct: number | null;
    mom_net_change_amount: number | null;
    avg_monthly_payroll_6m: number;
    monthly_trend: MonthlyTrend[];
    monthly_category_costs: MonthlyCategoryCost[];
    attention_items: AttentionItem[];
    salary_breakdown: {
        basic: number;
        allowances: number;
        deductions: number;
    } | null;
    department_costs: { name: string; total: number }[] | null;
    department_employee_counts: { name: string; count: number }[];
    category_split: { name: string; total: number }[] | null;
    wps_status_breakdown: { pending: number; submitted: number } | null;
    top_earners: TopEarner[] | null;
};

type CanPermissions = {
    view_periods: boolean;
    view_records: boolean;
    create_period: boolean;
    view_crew_timesheets: boolean;
    generate_payslips_from_sheet: boolean;
};

export type PayrollOverviewProps = {
    summary: OverviewSummary;
    can: CanPermissions;
};

/* ─────────────────────── constants ─────────────────────── */

const SEVERITY_BADGE: Record<string, 'destructive' | 'warning' | 'secondary'> =
    { warning: 'warning', info: 'secondary' };

const TYPE_LABELS: Record<string, string> = {
    draft: 'Draft',
    pending_approval: 'Pending approval',
    approved: 'Approved',
};

const TOOLTIP_STYLE = {
    borderRadius: '12px',
    border: '1px solid hsl(var(--border))',
    background: 'hsl(var(--card))',
    boxShadow: '0 10px 25px -5px rgba(0,0,0,0.15)',
    fontSize: '12px',
    color: 'hsl(var(--foreground))',
};

/* ─────────────────────── main component ─────────────────────── */

export function PayrollOverviewContent({
    summary,
    can,
}: PayrollOverviewProps): ReactElement {
    const { settings } = usePage().props;
    const currency =
        settings.company?.currency?.code || settings.currency || 'AED';
    const [salarySheetDialogOpen, setSalarySheetDialogOpen] = useState(false);

    const fmt = (amount: number) =>
        new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
            maximumFractionDigits: 0,
        }).format(amount);

    const fmtCompact = (amount: number) => {
        if (amount >= 1_000_000) {
            return `${(amount / 1_000_000).toFixed(1)}M`;
        }

        if (amount >= 1_000) {
            return `${(amount / 1_000).toFixed(1)}K`;
        }

        return fmt(amount);
    };

    const fmtFull = (amount: number) => `${currency} ${fmtCompact(amount)}`;

    const tooltipNumber = (value: unknown): number =>
        typeof value === 'number' ? value : 0;

    const today = new Date().toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const hasUrgentItems = summary.processing_periods > 0;
    const hasTrendData = summary.monthly_trend.some((m) => m.total > 0);
    const hasCategoryData = summary.monthly_category_costs.some(
        (m) => m.crew > 0 || m.office > 0,
    );
    const hasAnalytics =
        summary.salary_breakdown ||
        summary.department_costs ||
        summary.category_split;

    const momPositive =
        summary.mom_net_change_pct !== null && summary.mom_net_change_pct > 0;
    const momNegative =
        summary.mom_net_change_pct !== null && summary.mom_net_change_pct < 0;
    const momFlat =
        summary.mom_net_change_pct !== null && summary.mom_net_change_pct === 0;

    return (
        <Main>
            {/* ── Header ── */}
            <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <LayoutDashboard className="h-4 w-4 text-primary" />
                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                            Payroll
                        </span>
                    </div>
                    <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">
                        Overview
                    </h1>
                    <p className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground/60">
                        <CalendarDays className="h-3.5 w-3.5" />
                        {today}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    {can.generate_payslips_from_sheet && (
                        <Button
                            variant="outline"
                            className="rounded-xl glass-card"
                            onClick={() => setSalarySheetDialogOpen(true)}
                        >
                            <FileSpreadsheet className="mr-2 h-4 w-4" />
                            Generate payslips
                        </Button>
                    )}
                    {can.view_periods && (
                        <Button
                            variant="outline"
                            className="rounded-xl glass-card"
                            asChild
                        >
                            <Link href={payrollIndex.url()}>
                                <Wallet className="mr-2 h-4 w-4" />
                                Pay runs
                            </Link>
                        </Button>
                    )}
                    {can.view_records && (
                        <Button
                            variant="outline"
                            className="rounded-xl glass-card"
                            asChild
                        >
                            <Link href={recordsIndex.url()}>
                                <PiggyBank className="mr-2 h-4 w-4" />
                                Records
                            </Link>
                        </Button>
                    )}
                    {can.create_period && (
                        <Button className="rounded-xl" asChild>
                            <Link href={payrollIndex.url()}>
                                <Plus className="mr-2 h-4 w-4" />
                                New period
                            </Link>
                        </Button>
                    )}
                </div>
            </div>

            <SalarySheetPayslipDialog
                open={salarySheetDialogOpen}
                onOpenChange={setSalarySheetDialogOpen}
            />

            {/* ── Urgent alert ── */}
            {hasUrgentItems && (
                <Link
                    href={payrollIndex.url({ query: { status: 'processing' } })}
                    className="group mb-8 flex items-center gap-3 rounded-2xl border border-amber-500/25 bg-amber-500/5 px-5 py-4 transition-all duration-300 hover:border-amber-500/40 hover:bg-amber-500/10"
                >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-amber-500/20 bg-amber-500/10">
                        <Clock className="h-4 w-4 text-amber-400" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-bold text-amber-400">
                            Approval required
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground/75">
                            {summary.processing_periods} pay period
                            {summary.processing_periods !== 1 ? 's' : ''}{' '}
                            awaiting approval
                        </p>
                    </div>
                    <ChevronRight className="h-4 w-4 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5" />
                </Link>
            )}

            {/* ── Section: Period Status KPIs ── */}
            <SectionLabel icon={BarChart3} label="Pay period status" />
            <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                <MetricCard
                    title="Draft"
                    value={summary.draft_periods.toString()}
                    hint="Ready to generate"
                    icon={FileText}
                    iconColor="text-slate-400"
                    iconBg="bg-slate-500/10 border-slate-500/20"
                    accent="border-slate-500/20 hover:border-slate-500/30"
                    href={
                        can.view_periods
                            ? payrollIndex.url({ query: { status: 'draft' } })
                            : undefined
                    }
                />
                <MetricCard
                    title="Processing"
                    value={summary.processing_periods.toString()}
                    hint="Awaiting approval"
                    icon={Clock}
                    iconColor="text-amber-400"
                    iconBg="bg-amber-500/10 border-amber-500/20"
                    accent="border-amber-500/20 hover:border-amber-500/30"
                    href={
                        can.view_periods
                            ? payrollIndex.url({
                                  query: { status: 'processing' },
                              })
                            : undefined
                    }
                />
                <MetricCard
                    title="Approved"
                    value={summary.approved_periods.toString()}
                    hint="Ready to pay"
                    icon={CheckCircle2}
                    iconColor="text-emerald-400"
                    iconBg="bg-emerald-500/10 border-emerald-500/20"
                    accent="border-emerald-500/20 hover:border-emerald-500/30"
                    href={
                        can.view_periods
                            ? payrollIndex.url({
                                  query: { status: 'approved' },
                              })
                            : undefined
                    }
                />
                <MetricCard
                    title="Paid"
                    value={summary.paid_periods.toString()}
                    hint="Completed periods"
                    icon={CircleDollarSign}
                    iconColor="text-primary"
                    iconBg="bg-primary/10 border-primary/20"
                    accent="border-primary/20 hover:border-primary/30"
                    href={
                        can.view_periods
                            ? payrollIndex.url({ query: { status: 'paid' } })
                            : undefined
                    }
                />
                <MetricCard
                    title="Employees"
                    value={summary.total_employees_in_payroll.toString()}
                    hint="Active in payroll"
                    icon={Users}
                    iconColor="text-violet-400"
                    iconBg="bg-violet-500/10 border-violet-500/20"
                    accent="border-violet-500/20 hover:border-violet-500/30"
                />
                <MetricCard
                    title="YTD Payroll"
                    value={fmtFull(summary.ytd_payroll_total)}
                    hint={`${new Date().getFullYear()} net paid`}
                    icon={TrendingUp}
                    iconColor="text-cyan-400"
                    iconBg="bg-cyan-500/10 border-cyan-500/20"
                    accent="border-cyan-500/20 hover:border-cyan-500/30"
                    valueSmall
                />
            </div>

            {/* ── Section: YTD Financial Summary ── */}
            <SectionLabel icon={Sigma} label="Year-to-date financials" />
            <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <YtdCard
                    label="YTD Gross"
                    value={fmtFull(summary.ytd_gross_total)}
                    sub="Total gross salary paid"
                    color="blue"
                    icon={Wallet}
                />
                <YtdCard
                    label="YTD Net"
                    value={fmtFull(summary.ytd_payroll_total)}
                    sub="After all deductions"
                    color="emerald"
                    icon={CircleDollarSign}
                />
                <YtdCard
                    label="YTD Deductions"
                    value={fmtFull(summary.ytd_deductions_total)}
                    sub="Total withheld"
                    color="red"
                    icon={TrendingDown}
                />
                <YtdCard
                    label="YTD Overtime"
                    value={fmtFull(summary.ytd_overtime_total)}
                    sub="Overtime pay disbursed"
                    color="amber"
                    icon={Zap}
                />
            </div>

            {/* ── Section: Performance Metrics ── */}
            <SectionLabel icon={Target} label="Performance metrics" />
            <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {/* MoM Change */}
                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardContent className="p-5">
                        <p className="text-[10px] font-bold tracking-widest text-muted-foreground/50 uppercase">
                            Month-over-month
                        </p>
                        <div className="mt-2 flex items-center gap-2">
                            {summary.mom_net_change_pct !== null ? (
                                <>
                                    <div
                                        className={cn(
                                            'flex h-8 w-8 items-center justify-center rounded-lg',
                                            momPositive &&
                                                'bg-emerald-500/10 text-emerald-400',
                                            momNegative &&
                                                'bg-red-500/10 text-red-400',
                                            momFlat &&
                                                'bg-muted/20 text-muted-foreground/50',
                                        )}
                                    >
                                        {momPositive && (
                                            <ArrowUpRight className="h-4 w-4" />
                                        )}
                                        {momNegative && (
                                            <ArrowDownRight className="h-4 w-4" />
                                        )}
                                        {momFlat && (
                                            <Minus className="h-4 w-4" />
                                        )}
                                    </div>
                                    <span
                                        className={cn(
                                            'text-2xl font-black tabular-nums',
                                            momPositive && 'text-emerald-400',
                                            momNegative && 'text-red-400',
                                            momFlat &&
                                                'text-muted-foreground/60',
                                        )}
                                    >
                                        {momPositive ? '+' : ''}
                                        {summary.mom_net_change_pct}%
                                    </span>
                                </>
                            ) : (
                                <span className="text-xl font-black text-muted-foreground/30">
                                    —
                                </span>
                            )}
                        </div>
                        {summary.mom_net_change_amount !== null && (
                            <p className="mt-1 text-xs text-muted-foreground/55">
                                {summary.mom_net_change_amount > 0 ? '+' : ''}
                                {fmt(summary.mom_net_change_amount)} vs prior
                                month
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Payroll Efficiency */}
                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardContent className="p-5">
                        <p className="text-[10px] font-bold tracking-widest text-muted-foreground/50 uppercase">
                            Payroll efficiency
                        </p>
                        <div className="mt-2">
                            <span className="text-2xl font-black tabular-nums">
                                {summary.payroll_efficiency_pct !== null
                                    ? `${summary.payroll_efficiency_pct}%`
                                    : '—'}
                            </span>
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground/55">
                            Net as % of gross (YTD)
                        </p>
                        {summary.payroll_efficiency_pct !== null && (
                            <div className="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-muted/30">
                                <div
                                    className="h-full rounded-full bg-primary/60 transition-all duration-700"
                                    style={{
                                        width: `${summary.payroll_efficiency_pct}%`,
                                    }}
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* 6-month Avg */}
                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardContent className="p-5">
                        <p className="text-[10px] font-bold tracking-widest text-muted-foreground/50 uppercase">
                            6-month average
                        </p>
                        <div className="mt-2">
                            <span className="text-2xl font-black tabular-nums">
                                {summary.avg_monthly_payroll_6m > 0
                                    ? fmtFull(summary.avg_monthly_payroll_6m)
                                    : '—'}
                            </span>
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground/55">
                            Avg net per paid month
                        </p>
                    </CardContent>
                </Card>

                {/* Last period emp count */}
                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardContent className="p-5">
                        <p className="text-[10px] font-bold tracking-widest text-muted-foreground/50 uppercase">
                            Last period
                        </p>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="text-2xl font-black tabular-nums">
                                {summary.last_paid_period_employee_count ?? '—'}
                            </span>
                            {summary.last_paid_period_employee_count !==
                                null && (
                                <span className="text-xs text-muted-foreground/50">
                                    employees
                                </span>
                            )}
                        </div>
                        <p className="mt-1 truncate text-xs text-muted-foreground/55">
                            {summary.last_paid_period_name ?? 'No paid periods'}
                        </p>
                    </CardContent>
                </Card>
            </div>

            {/* ── Section: Workforce split ── */}
            {(summary.crew_employee_count > 0 ||
                summary.office_employee_count > 0) && (
                <div className="mb-6 grid gap-4 sm:grid-cols-2">
                    <WorkforceCard
                        label="Crew employees"
                        count={summary.crew_employee_count}
                        total={summary.total_employees_in_payroll}
                        icon={Ship}
                        color="violet"
                    />
                    <WorkforceCard
                        label="Office employees"
                        count={summary.office_employee_count}
                        total={summary.total_employees_in_payroll}
                        icon={Briefcase}
                        color="blue"
                    />
                </div>
            )}

            {/* ── Section: Trend + Attention ── */}
            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                {/* Payroll Trend — Area chart */}
                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight">
                                    Net payroll trend
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Net salary paid — last 6 months
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10">
                                <BarChart3 className="h-4 w-4 text-primary" />
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5">
                        {!hasTrendData ? (
                            <EmptyState label="No paid payroll data in the last 6 months" />
                        ) : (
                            <>
                                <div className="h-52 w-full">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <AreaChart
                                            data={summary.monthly_trend}
                                            margin={{
                                                top: 4,
                                                right: 4,
                                                left: 4,
                                                bottom: 0,
                                            }}
                                        >
                                            <defs>
                                                <linearGradient
                                                    id="gTotal"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="5%"
                                                        stopColor="hsl(var(--primary))"
                                                        stopOpacity={0.35}
                                                    />
                                                    <stop
                                                        offset="95%"
                                                        stopColor="hsl(var(--primary))"
                                                        stopOpacity={0.02}
                                                    />
                                                </linearGradient>
                                                <linearGradient
                                                    id="gAvg"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="5%"
                                                        stopColor="#a78bfa"
                                                        stopOpacity={0.15}
                                                    />
                                                    <stop
                                                        offset="95%"
                                                        stopColor="#a78bfa"
                                                        stopOpacity={0}
                                                    />
                                                </linearGradient>
                                            </defs>
                                            <XAxis
                                                dataKey="month"
                                                tick={{
                                                    fontSize: 10,
                                                    fill: 'hsl(var(--muted-foreground) / 0.6)',
                                                }}
                                                axisLine={false}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                tick={{
                                                    fontSize: 10,
                                                    fill: 'hsl(var(--muted-foreground) / 0.6)',
                                                }}
                                                axisLine={false}
                                                tickLine={false}
                                                tickFormatter={fmtCompact}
                                                width={64}
                                            />
                                            <Tooltip
                                                formatter={(value, name) => [
                                                    fmt(tooltipNumber(value)),
                                                    name === 'total'
                                                        ? 'Net Total'
                                                        : 'Avg / Employee',
                                                ]}
                                                contentStyle={TOOLTIP_STYLE}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="total"
                                                stroke="hsl(var(--primary))"
                                                strokeWidth={2}
                                                fill="url(#gTotal)"
                                                dot={false}
                                                activeDot={{
                                                    r: 4,
                                                    strokeWidth: 0,
                                                }}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="avg"
                                                stroke="#a78bfa"
                                                strokeWidth={1.5}
                                                strokeDasharray="4 2"
                                                fill="url(#gAvg)"
                                                dot={false}
                                                activeDot={{
                                                    r: 3,
                                                    strokeWidth: 0,
                                                }}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                                <div className="mt-2 flex items-center gap-5 px-1 text-[11px] text-muted-foreground/55">
                                    <div className="flex items-center gap-1.5">
                                        <div className="h-2 w-4 rounded-full bg-primary/70" />
                                        Net total
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <div className="h-px w-4 border-t-2 border-dashed border-violet-400" />
                                        Avg/employee
                                    </div>
                                </div>
                            </>
                        )}
                        {summary.last_paid_period_name !== null &&
                            summary.last_paid_period_total !== null && (
                                <div className="mt-4 grid grid-cols-3 gap-2">
                                    <PeriodStat
                                        label="Net paid"
                                        value={fmtFull(
                                            summary.last_paid_period_total,
                                        )}
                                    />
                                    <PeriodStat
                                        label="Gross"
                                        value={
                                            summary.last_paid_period_gross !==
                                            null
                                                ? fmtFull(
                                                      summary.last_paid_period_gross,
                                                  )
                                                : '—'
                                        }
                                    />
                                    <PeriodStat
                                        label="Avg/emp"
                                        value={
                                            summary.last_paid_period_avg_net !==
                                            null
                                                ? fmtFull(
                                                      summary.last_paid_period_avg_net,
                                                  )
                                                : '—'
                                        }
                                    />
                                </div>
                            )}
                    </CardContent>
                </Card>

                {/* Attention Required */}
                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight">
                                    Attention required
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Pay periods that need action
                                </CardDescription>
                            </div>
                            {can.view_periods && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-8 rounded-lg text-xs"
                                    asChild
                                >
                                    <Link href={payrollIndex.url()}>
                                        View all
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2 pt-4">
                        {summary.attention_items.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
                                <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-emerald-500/20 bg-emerald-500/10">
                                    <CheckCircle2 className="h-5 w-5 text-emerald-400" />
                                </div>
                                <p className="text-sm font-medium text-muted-foreground/50">
                                    Everything is up to date!
                                </p>
                            </div>
                        ) : (
                            summary.attention_items.map((item, index) => (
                                <Link
                                    key={`${item.type}-${index}`}
                                    href={payrollIndex.url({
                                        query: {
                                            status:
                                                item.type === 'pending_approval'
                                                    ? 'processing'
                                                    : item.type === 'approved'
                                                      ? 'approved'
                                                      : 'draft',
                                        },
                                    })}
                                    className="group flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/[0.01] dark:hover:border-white/10"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="truncate text-sm font-semibold text-foreground/80 group-hover:text-primary">
                                                {item.title}
                                            </p>
                                            <Badge
                                                variant={
                                                    SEVERITY_BADGE[
                                                        item.severity
                                                    ] ?? 'secondary'
                                                }
                                            >
                                                {TYPE_LABELS[item.type] ??
                                                    item.type}
                                            </Badge>
                                        </div>
                                        <p className="mt-0.5 truncate text-xs text-muted-foreground/60">
                                            {item.subtitle}
                                        </p>
                                    </div>
                                    <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground/45 opacity-0 transition-all group-hover:opacity-100" />
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* ── Section: Gross vs Net vs Deductions — Grouped bar ── */}
            {hasTrendData && (
                <>
                    <SectionLabel
                        icon={BarChart3}
                        label="Monthly financial breakdown"
                    />
                    <div className="mb-6 grid gap-6 lg:grid-cols-2">
                        {/* Gross vs Net grouped bar */}
                        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                <CardTitle className="text-base font-bold tracking-tight">
                                    Gross vs Net
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Monthly comparison — last 6 months
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="pt-5">
                                <div className="h-56 w-full">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <BarChart
                                            data={summary.monthly_trend}
                                            margin={{
                                                top: 4,
                                                right: 4,
                                                left: 4,
                                                bottom: 0,
                                            }}
                                            barCategoryGap="30%"
                                            barGap={2}
                                        >
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                vertical={false}
                                                stroke="hsl(var(--border) / 0.4)"
                                            />
                                            <XAxis
                                                dataKey="month"
                                                tick={{
                                                    fontSize: 10,
                                                    fill: 'hsl(var(--muted-foreground) / 0.6)',
                                                }}
                                                axisLine={false}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                tick={{
                                                    fontSize: 10,
                                                    fill: 'hsl(var(--muted-foreground) / 0.6)',
                                                }}
                                                axisLine={false}
                                                tickLine={false}
                                                tickFormatter={fmtCompact}
                                                width={60}
                                            />
                                            <Tooltip
                                                formatter={(value, name) => [
                                                    fmt(tooltipNumber(value)),
                                                    name === 'gross'
                                                        ? 'Gross'
                                                        : 'Net',
                                                ]}
                                                contentStyle={TOOLTIP_STYLE}
                                            />
                                            <Bar
                                                dataKey="gross"
                                                fill="#3b82f6"
                                                fillOpacity={0.75}
                                                radius={[4, 4, 0, 0]}
                                            />
                                            <Bar
                                                dataKey="total"
                                                fill="hsl(var(--primary))"
                                                fillOpacity={0.9}
                                                radius={[4, 4, 0, 0]}
                                            />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                                <div className="mt-2 flex items-center gap-5 px-1 text-[11px] text-muted-foreground/55">
                                    <div className="flex items-center gap-1.5">
                                        <div className="h-2.5 w-2.5 rounded-sm bg-blue-500/75" />
                                        Gross
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <div className="h-2.5 w-2.5 rounded-sm bg-primary/90" />
                                        Net
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Deductions & Overtime area */}
                        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                <CardTitle className="text-base font-bold tracking-tight">
                                    Deductions & Overtime
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Monthly trend — last 6 months
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="pt-5">
                                <div className="h-56 w-full">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <AreaChart
                                            data={summary.monthly_trend}
                                            margin={{
                                                top: 4,
                                                right: 4,
                                                left: 4,
                                                bottom: 0,
                                            }}
                                        >
                                            <defs>
                                                <linearGradient
                                                    id="gDed"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="5%"
                                                        stopColor="#ef4444"
                                                        stopOpacity={0.3}
                                                    />
                                                    <stop
                                                        offset="95%"
                                                        stopColor="#ef4444"
                                                        stopOpacity={0.02}
                                                    />
                                                </linearGradient>
                                                <linearGradient
                                                    id="gOT"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="5%"
                                                        stopColor="#f59e0b"
                                                        stopOpacity={0.3}
                                                    />
                                                    <stop
                                                        offset="95%"
                                                        stopColor="#f59e0b"
                                                        stopOpacity={0.02}
                                                    />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                vertical={false}
                                                stroke="hsl(var(--border) / 0.4)"
                                            />
                                            <XAxis
                                                dataKey="month"
                                                tick={{
                                                    fontSize: 10,
                                                    fill: 'hsl(var(--muted-foreground) / 0.6)',
                                                }}
                                                axisLine={false}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                tick={{
                                                    fontSize: 10,
                                                    fill: 'hsl(var(--muted-foreground) / 0.6)',
                                                }}
                                                axisLine={false}
                                                tickLine={false}
                                                tickFormatter={fmtCompact}
                                                width={60}
                                            />
                                            <Tooltip
                                                formatter={(value, name) => [
                                                    fmt(tooltipNumber(value)),
                                                    name === 'deductions'
                                                        ? 'Deductions'
                                                        : 'Overtime',
                                                ]}
                                                contentStyle={TOOLTIP_STYLE}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="deductions"
                                                stroke="#ef4444"
                                                strokeWidth={2}
                                                fill="url(#gDed)"
                                                dot={false}
                                                activeDot={{
                                                    r: 4,
                                                    strokeWidth: 0,
                                                }}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="overtime"
                                                stroke="#f59e0b"
                                                strokeWidth={2}
                                                fill="url(#gOT)"
                                                dot={false}
                                                activeDot={{
                                                    r: 4,
                                                    strokeWidth: 0,
                                                }}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </div>
                                <div className="mt-2 flex items-center gap-5 px-1 text-[11px] text-muted-foreground/55">
                                    <div className="flex items-center gap-1.5">
                                        <div className="h-2 w-4 rounded-full bg-red-500/70" />
                                        Deductions
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <div className="h-2 w-4 rounded-full bg-amber-500/70" />
                                        Overtime
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </>
            )}

            {/* ── Section: Crew vs Office cost trend ── */}
            {hasCategoryData && (
                <>
                    <SectionLabel
                        icon={Users}
                        label="Crew vs Office cost trend"
                    />
                    <div className="mb-6">
                        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <CardTitle className="text-base font-bold tracking-tight">
                                            Crew vs Office — monthly net cost
                                        </CardTitle>
                                        <CardDescription className="text-xs">
                                            Side-by-side comparison over the
                                            last 6 months
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="pt-5">
                                <div className="h-56 w-full">
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                    >
                                        <LineChart
                                            data={
                                                summary.monthly_category_costs
                                            }
                                            margin={{
                                                top: 4,
                                                right: 4,
                                                left: 4,
                                                bottom: 0,
                                            }}
                                        >
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                vertical={false}
                                                stroke="hsl(var(--border) / 0.4)"
                                            />
                                            <XAxis
                                                dataKey="month"
                                                tick={{
                                                    fontSize: 10,
                                                    fill: 'hsl(var(--muted-foreground) / 0.6)',
                                                }}
                                                axisLine={false}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                tick={{
                                                    fontSize: 10,
                                                    fill: 'hsl(var(--muted-foreground) / 0.6)',
                                                }}
                                                axisLine={false}
                                                tickLine={false}
                                                tickFormatter={fmtCompact}
                                                width={64}
                                            />
                                            <Tooltip
                                                formatter={(value, name) => [
                                                    fmt(tooltipNumber(value)),
                                                    name === 'crew'
                                                        ? 'Crew'
                                                        : 'Office',
                                                ]}
                                                contentStyle={TOOLTIP_STYLE}
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="crew"
                                                stroke="#8b5cf6"
                                                strokeWidth={2.5}
                                                dot={{
                                                    r: 3,
                                                    fill: '#8b5cf6',
                                                    strokeWidth: 0,
                                                }}
                                                activeDot={{ r: 5 }}
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="office"
                                                stroke="#3b82f6"
                                                strokeWidth={2.5}
                                                strokeDasharray="5 3"
                                                dot={{
                                                    r: 3,
                                                    fill: '#3b82f6',
                                                    strokeWidth: 0,
                                                }}
                                                activeDot={{ r: 5 }}
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </div>
                                <div className="mt-2 flex items-center gap-5 px-1 text-[11px] text-muted-foreground/55">
                                    <div className="flex items-center gap-1.5">
                                        <div className="h-2 w-4 rounded-full bg-violet-500" />
                                        Crew
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <div className="h-px w-4 border-t-2 border-dashed border-blue-500" />
                                        Office
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </>
            )}

            {/* ── Section: Last Period Analytics ── */}
            {hasAnalytics && (
                <>
                    <SectionLabel
                        icon={BarChart3}
                        label="Analytics — last paid period"
                    />

                    {/* Row 1: Three pie/donut charts */}
                    <div className="mb-6 grid gap-6 lg:grid-cols-3">
                        {summary.salary_breakdown && (
                            <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                                <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Salary components
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Basic · Allowances · Deductions
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col items-center pt-5">
                                    <div className="h-44 w-full">
                                        <ResponsiveContainer
                                            width="100%"
                                            height="100%"
                                        >
                                            <PieChart>
                                                <Pie
                                                    data={[
                                                        {
                                                            name: 'Basic',
                                                            value: summary
                                                                .salary_breakdown
                                                                .basic,
                                                        },
                                                        {
                                                            name: 'Allowances',
                                                            value: summary
                                                                .salary_breakdown
                                                                .allowances,
                                                        },
                                                        {
                                                            name: 'Deductions',
                                                            value: summary
                                                                .salary_breakdown
                                                                .deductions,
                                                        },
                                                    ]}
                                                    cx="50%"
                                                    cy="50%"
                                                    innerRadius={46}
                                                    outerRadius={66}
                                                    paddingAngle={3}
                                                    dataKey="value"
                                                >
                                                    {[
                                                        '#3b82f6',
                                                        '#10b981',
                                                        '#ef4444',
                                                    ].map((color, i) => (
                                                        <Cell
                                                            key={i}
                                                            fill={color}
                                                        />
                                                    ))}
                                                </Pie>
                                                <Tooltip
                                                    formatter={(v) =>
                                                        fmt(tooltipNumber(v))
                                                    }
                                                    contentStyle={TOOLTIP_STYLE}
                                                />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="mt-1 grid w-full grid-cols-3 gap-1 text-[11px] text-muted-foreground">
                                        {[
                                            {
                                                label: 'Basic',
                                                color: 'bg-blue-500',
                                            },
                                            {
                                                label: 'Allow.',
                                                color: 'bg-emerald-500',
                                            },
                                            {
                                                label: 'Deduct.',
                                                color: 'bg-red-500',
                                            },
                                        ].map((l) => (
                                            <div
                                                key={l.label}
                                                className="flex items-center gap-1"
                                            >
                                                <div
                                                    className={cn(
                                                        'h-2 w-2 shrink-0 rounded-full',
                                                        l.color,
                                                    )}
                                                />
                                                {l.label}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {summary.category_split && (
                            <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                                <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Payroll category
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Crew vs Office split
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col items-center pt-5">
                                    <div className="h-44 w-full">
                                        <ResponsiveContainer
                                            width="100%"
                                            height="100%"
                                        >
                                            <PieChart>
                                                <Pie
                                                    data={
                                                        summary.category_split
                                                    }
                                                    cx="50%"
                                                    cy="50%"
                                                    innerRadius={46}
                                                    outerRadius={66}
                                                    paddingAngle={3}
                                                    dataKey="total"
                                                >
                                                    {summary.category_split.map(
                                                        (_, i) => (
                                                            <Cell
                                                                key={i}
                                                                fill={
                                                                    [
                                                                        '#8b5cf6',
                                                                        '#f59e0b',
                                                                    ][i % 2]
                                                                }
                                                            />
                                                        ),
                                                    )}
                                                </Pie>
                                                <Tooltip
                                                    formatter={(v) =>
                                                        fmt(tooltipNumber(v))
                                                    }
                                                    contentStyle={TOOLTIP_STYLE}
                                                />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="mt-1 flex w-full justify-center gap-4 text-[11px] text-muted-foreground">
                                        {summary.category_split.map((c, i) => (
                                            <div
                                                key={c.name}
                                                className="flex items-center gap-1.5"
                                            >
                                                <div
                                                    className="h-2 w-2 rounded-full"
                                                    style={{
                                                        backgroundColor: [
                                                            '#8b5cf6',
                                                            '#f59e0b',
                                                        ][i % 2],
                                                    }}
                                                />
                                                {c.name}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {summary.wps_status_breakdown && (
                            <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                                <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        WPS status
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Submitted vs pending — last period
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col items-center pt-5">
                                    <div className="h-44 w-full">
                                        <ResponsiveContainer
                                            width="100%"
                                            height="100%"
                                        >
                                            <PieChart>
                                                <Pie
                                                    data={[
                                                        {
                                                            name: 'Submitted',
                                                            value: summary
                                                                .wps_status_breakdown
                                                                .submitted,
                                                        },
                                                        {
                                                            name: 'Pending',
                                                            value: summary
                                                                .wps_status_breakdown
                                                                .pending,
                                                        },
                                                    ]}
                                                    cx="50%"
                                                    cy="50%"
                                                    innerRadius={46}
                                                    outerRadius={66}
                                                    paddingAngle={3}
                                                    dataKey="value"
                                                >
                                                    {['#10b981', '#f59e0b'].map(
                                                        (color, i) => (
                                                            <Cell
                                                                key={i}
                                                                fill={color}
                                                            />
                                                        ),
                                                    )}
                                                </Pie>
                                                <Tooltip
                                                    formatter={(v, name) => {
                                                        const count =
                                                            tooltipNumber(v);

                                                        return [
                                                            `${count} emp${count !== 1 ? 's' : ''}`,
                                                            name,
                                                        ];
                                                    }}
                                                    contentStyle={TOOLTIP_STYLE}
                                                />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="mt-1 grid w-full grid-cols-2 gap-2 text-[11px]">
                                        <div className="rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-3 py-2 text-center">
                                            <p className="text-lg font-black text-emerald-400">
                                                {
                                                    summary.wps_status_breakdown
                                                        .submitted
                                                }
                                            </p>
                                            <p className="text-muted-foreground/60">
                                                Submitted
                                            </p>
                                        </div>
                                        <div className="rounded-lg border border-amber-500/20 bg-amber-500/5 px-3 py-2 text-center">
                                            <p className="text-lg font-black text-amber-400">
                                                {
                                                    summary.wps_status_breakdown
                                                        .pending
                                                }
                                            </p>
                                            <p className="text-muted-foreground/60">
                                                Pending
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Row 2: Dept cost bar + Top earners */}
                    <div className="mb-6 grid gap-6 lg:grid-cols-2">
                        {summary.department_costs && (
                            <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                                <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Department payroll cost
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Net salary by department — last period
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="pt-5">
                                    <div className="h-64 w-full">
                                        <ResponsiveContainer
                                            width="100%"
                                            height="100%"
                                        >
                                            <BarChart
                                                data={summary.department_costs.slice(
                                                    0,
                                                    8,
                                                )}
                                                layout="vertical"
                                                margin={{
                                                    top: 0,
                                                    right: 8,
                                                    left: 0,
                                                    bottom: 0,
                                                }}
                                            >
                                                <XAxis type="number" hide />
                                                <YAxis
                                                    dataKey="name"
                                                    type="category"
                                                    width={90}
                                                    tick={{
                                                        fontSize: 11,
                                                        fill: 'hsl(var(--muted-foreground) / 0.7)',
                                                    }}
                                                    axisLine={false}
                                                    tickLine={false}
                                                />
                                                <Tooltip
                                                    formatter={(v) =>
                                                        fmt(tooltipNumber(v))
                                                    }
                                                    cursor={{
                                                        fill: 'rgba(0,0,0,0.04)',
                                                    }}
                                                    contentStyle={TOOLTIP_STYLE}
                                                />
                                                <Bar
                                                    dataKey="total"
                                                    radius={[0, 6, 6, 0]}
                                                    barSize={18}
                                                >
                                                    {summary.department_costs
                                                        .slice(0, 8)
                                                        .map((_, i) => (
                                                            <Cell
                                                                key={i}
                                                                fill={`hsl(${220 + i * 22}, 70%, 60%)`}
                                                                fillOpacity={
                                                                    0.85
                                                                }
                                                            />
                                                        ))}
                                                </Bar>
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {summary.top_earners && (
                            <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                                <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                    <div className="flex items-center justify-between gap-4">
                                        <div>
                                            <CardTitle className="text-base font-bold tracking-tight">
                                                Top earners
                                            </CardTitle>
                                            <CardDescription className="text-xs">
                                                Highest net salary — last period
                                            </CardDescription>
                                        </div>
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-amber-500/20 bg-amber-500/10">
                                            <Award className="h-4 w-4 text-amber-400" />
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent className="pt-4">
                                    <div className="space-y-1">
                                        {summary.top_earners.map(
                                            (earner, i) => (
                                                <div
                                                    key={earner.employee_no}
                                                    className="flex items-center gap-3 rounded-xl px-3 py-2.5 transition-colors hover:bg-muted/20"
                                                >
                                                    <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-border/60 bg-muted/20 text-[11px] font-black text-muted-foreground/60">
                                                        {i + 1}
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate text-sm font-semibold">
                                                            {earner.name}
                                                        </p>
                                                        <p className="truncate text-[11px] text-muted-foreground/55">
                                                            {earner.employee_no}
                                                            {earner.department
                                                                ? ` · ${earner.department}`
                                                                : ''}
                                                        </p>
                                                    </div>
                                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                                        <span className="text-sm font-bold tabular-nums">
                                                            {fmt(
                                                                earner.net_salary,
                                                            )}
                                                        </span>
                                                        <Badge
                                                            variant="secondary"
                                                            className="h-4 px-1.5 text-[10px]"
                                                        >
                                                            {earner.category}
                                                        </Badge>
                                                    </div>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </>
            )}

            {/* ── Section: Department headcount ── */}
            {summary.department_employee_counts.length > 0 && (
                <>
                    <SectionLabel icon={Users} label="Department headcount" />
                    <div className="mb-6">
                        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                <CardTitle className="text-base font-bold tracking-tight">
                                    Active employees by department
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Current headcount across departments
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="pt-5">
                                <div className="space-y-3">
                                    {(() => {
                                        const maxCount = Math.max(
                                            ...summary.department_employee_counts.map(
                                                (d) => d.count,
                                            ),
                                            1,
                                        );

                                        return summary.department_employee_counts.map(
                                            (dept, i) => (
                                                <div
                                                    key={dept.name}
                                                    className="flex items-center gap-3"
                                                >
                                                    <span className="w-28 shrink-0 truncate text-right text-[11px] font-medium text-muted-foreground/65">
                                                        {dept.name}
                                                    </span>
                                                    <div className="relative h-7 flex-1 overflow-hidden rounded-full bg-muted/25">
                                                        <div
                                                            className="h-full rounded-full transition-all duration-700"
                                                            style={{
                                                                width: `${Math.max((dept.count / maxCount) * 100, dept.count > 0 ? 3 : 0)}%`,
                                                                background: `hsl(${200 + i * 28}, 65%, 55%)`,
                                                                opacity: 0.8,
                                                            }}
                                                        />
                                                        <span className="absolute inset-y-0 left-3 flex items-center text-[11px] font-bold text-foreground/80">
                                                            {dept.count}{' '}
                                                            <span className="ml-1 font-normal text-muted-foreground/50">
                                                                {dept.count ===
                                                                1
                                                                    ? 'employee'
                                                                    : 'employees'}
                                                            </span>
                                                        </span>
                                                    </div>
                                                </div>
                                            ),
                                        );
                                    })()}
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </>
            )}
        </Main>
    );
}

/* ─────────────────────── sub-components ─────────────────────── */

function SectionLabel({
    icon: Icon,
    label,
}: {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
}): ReactElement {
    return (
        <div className="mb-4 flex items-center gap-2 select-none">
            <Icon className="h-3.5 w-3.5 text-muted-foreground/50" />
            <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/50 uppercase">
                {label}
            </span>
            <div className="h-px flex-1 bg-border/80 dark:bg-white/5" />
        </div>
    );
}

function MetricCard({
    title,
    value,
    hint,
    icon: Icon,
    iconColor = 'text-muted-foreground',
    iconBg = 'bg-muted/40',
    accent = 'border-border',
    href,
    valueSmall = false,
}: {
    title: string;
    value: string;
    hint: string;
    icon: React.ComponentType<{ className?: string }>;
    iconColor?: string;
    iconBg?: string;
    accent?: string;
    href?: string;
    valueSmall?: boolean;
}): ReactElement {
    const content = (
        <Card
            className={cn(
                'group gap-0 overflow-hidden glass-card p-0 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl',
                accent,
                href && 'cursor-pointer',
            )}
        >
            <CardHeader className="relative flex flex-row items-center justify-between space-y-0 px-5 pt-4 pb-1">
                <CardTitle className="text-[10px] font-bold tracking-wider text-muted-foreground/85 uppercase">
                    {title}
                </CardTitle>
                <div
                    className={cn(
                        'flex h-9 w-9 items-center justify-center rounded-xl border',
                        iconBg,
                    )}
                >
                    <Icon className={cn('h-4 w-4', iconColor)} />
                </div>
            </CardHeader>
            <CardContent className="relative px-5 pt-0 pb-4">
                <div
                    className={cn(
                        'font-black tracking-tight tabular-nums',
                        valueSmall ? 'text-xl' : 'text-3xl',
                    )}
                >
                    {value}
                </div>
                <p className="mt-1.5 text-xs text-muted-foreground/80">
                    {hint}
                </p>
            </CardContent>
        </Card>
    );

    if (href) {
        return <Link href={href}>{content}</Link>;
    }

    return content;
}

function YtdCard({
    label,
    value,
    sub,
    color,
    icon: Icon,
}: {
    label: string;
    value: string;
    sub: string;
    color: 'blue' | 'emerald' | 'red' | 'amber';
    icon: React.ComponentType<{ className?: string }>;
}): ReactElement {
    const palette = {
        blue: {
            icon: 'text-blue-400',
            bg: 'bg-blue-500/10 border-blue-500/20',
            card: 'border-blue-500/15',
        },
        emerald: {
            icon: 'text-emerald-400',
            bg: 'bg-emerald-500/10 border-emerald-500/20',
            card: 'border-emerald-500/15',
        },
        red: {
            icon: 'text-red-400',
            bg: 'bg-red-500/10 border-red-500/20',
            card: 'border-red-500/15',
        },
        amber: {
            icon: 'text-amber-400',
            bg: 'bg-amber-500/10 border-amber-500/20',
            card: 'border-amber-500/15',
        },
    };

    const p = palette[color];

    return (
        <Card className={cn('overflow-hidden glass-card p-0', p.card)}>
            <CardContent className="flex items-center gap-4 p-5">
                <div
                    className={cn(
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border',
                        p.bg,
                    )}
                >
                    <Icon className={cn('h-5 w-5', p.icon)} />
                </div>
                <div className="min-w-0">
                    <p className="text-[10px] font-bold tracking-widest text-muted-foreground/50 uppercase">
                        {label}
                    </p>
                    <p className="mt-0.5 truncate text-xl font-black tabular-nums">
                        {value}
                    </p>
                    <p className="text-[11px] text-muted-foreground/55">
                        {sub}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

function WorkforceCard({
    label,
    count,
    total,
    icon: Icon,
    color,
}: {
    label: string;
    count: number;
    total: number;
    icon: React.ComponentType<{ className?: string }>;
    color: 'violet' | 'blue';
}): ReactElement {
    const pct = total > 0 ? Math.round((count / total) * 100) : 0;
    const palette = {
        violet: {
            icon: 'text-violet-400',
            iconBg: 'bg-violet-500/10 border-violet-500/20',
            bar: 'bg-violet-500/60',
            card: 'border-violet-500/15 dark:border-violet-500/10',
        },
        blue: {
            icon: 'text-blue-400',
            iconBg: 'bg-blue-500/10 border-blue-500/20',
            bar: 'bg-blue-500/60',
            card: 'border-blue-500/15 dark:border-blue-500/10',
        },
    };
    const c = palette[color];

    return (
        <Card className={cn('overflow-hidden glass-card p-0', c.card)}>
            <CardContent className="flex items-center gap-4 p-5">
                <div
                    className={cn(
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border',
                        c.iconBg,
                    )}
                >
                    <Icon className={cn('h-5 w-5', c.icon)} />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="text-xs font-semibold text-muted-foreground/60">
                        {label}
                    </p>
                    <div className="mt-1 flex items-baseline gap-2">
                        <span className="text-2xl font-black tabular-nums">
                            {count}
                        </span>
                        <span className="text-xs text-muted-foreground/50">
                            {pct}% of total
                        </span>
                    </div>
                    <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-muted/30">
                        <div
                            className={cn(
                                'h-full rounded-full transition-all duration-700',
                                c.bar,
                            )}
                            style={{ width: `${pct}%` }}
                        />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

function PeriodStat({
    label,
    value,
}: {
    label: string;
    value: string;
}): ReactElement {
    return (
        <div className="rounded-xl border border-border/60 bg-muted/10 px-3 py-2.5 dark:border-white/5">
            <p className="text-[10px] font-bold tracking-widest text-muted-foreground/50 uppercase">
                {label}
            </p>
            <p className="mt-0.5 text-sm font-black tabular-nums">{value}</p>
        </div>
    );
}

function EmptyState({ label }: { label: string }): ReactElement {
    return (
        <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
            <p className="text-sm font-medium text-muted-foreground/50">
                {label}
            </p>
        </div>
    );
}
