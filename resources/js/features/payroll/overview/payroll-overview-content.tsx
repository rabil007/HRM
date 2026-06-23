import { Link, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    BarChart3,
    CalendarDays,
    CheckCircle2,
    ChevronRight,
    CircleDollarSign,
    Clock,
    FileText,
    LayoutDashboard,
    PiggyBank,
    Plus,
    Users,
    Wallet,
} from 'lucide-react';
import type { ReactElement } from 'react';
import {
    Bar,
    BarChart,
    Cell,
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
import { cn } from '@/lib/utils';
import { index as payrollIndex } from '@/routes/payroll';
import { index as recordsIndex } from '@/routes/payroll/records';

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
};

type OverviewSummary = {
    draft_periods: number;
    processing_periods: number;
    approved_periods: number;
    paid_periods: number;
    total_employees_in_payroll: number;
    last_paid_period_total: number | null;
    last_paid_period_name: string | null;
    monthly_trend: MonthlyTrend[];
    attention_items: AttentionItem[];
    salary_breakdown: { basic: number; allowances: number; deductions: number } | null;
    department_costs: { name: string; total: number }[] | null;
    category_split: { name: string; total: number }[] | null;
};

type CanPermissions = {
    view_periods: boolean;
    view_records: boolean;
    create_period: boolean;
    view_crew_timesheets: boolean;
};

export type PayrollOverviewProps = {
    summary: OverviewSummary;
    can: CanPermissions;
};

const SEVERITY_BADGE: Record<string, 'destructive' | 'warning' | 'secondary'> = {
    warning: 'warning',
    info: 'secondary',
};

const TYPE_LABELS: Record<string, string> = {
    draft: 'Draft',
    pending_approval: 'Pending approval',
    approved: 'Approved',
};

export function PayrollOverviewContent({ summary, can }: PayrollOverviewProps): ReactElement {
    const { settings } = usePage().props;
    const currency = settings.currency || 'USD';

    function formatCurrency(amount: number): string {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency,
            maximumFractionDigits: 0,
        }).format(amount);
    }

    const today = new Date().toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const hasUrgentItems = summary.processing_periods > 0;
    const maxTrend = Math.max(...summary.monthly_trend.map((m) => m.total), 1);

    return (
        <Main>
            {/* Header */}
            <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <LayoutDashboard className="h-4 w-4 text-primary" />
                        <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/60">
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
                    {can.view_periods ? (
                        <Button variant="outline" className="glass-card rounded-xl" asChild>
                            <Link href={payrollIndex.url()}>
                                <Wallet className="mr-2 h-4 w-4" />
                                Pay runs
                            </Link>
                        </Button>
                    ) : null}
                    {can.view_records ? (
                        <Button variant="outline" className="glass-card rounded-xl" asChild>
                            <Link href={recordsIndex.url()}>
                                <PiggyBank className="mr-2 h-4 w-4" />
                                Records
                            </Link>
                        </Button>
                    ) : null}
                    {can.create_period ? (
                        <Button className="rounded-xl" asChild>
                            <Link href={payrollIndex.url()}>
                                <Plus className="mr-2 h-4 w-4" />
                                New period
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </div>

            {/* Urgent alert banner */}
            {hasUrgentItems ? (
                <Link
                    href={payrollIndex.url({ query: { status: 'processing' } })}
                    className="group mb-8 flex items-center gap-3 rounded-2xl border border-amber-500/25 bg-amber-500/5 px-5 py-4 transition-all duration-300 hover:border-amber-500/40 hover:bg-amber-500/10"
                >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-amber-500/20 bg-amber-500/10">
                        <Clock className="h-4 w-4 text-amber-400" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-bold text-amber-400">Approval required</p>
                        <p className="mt-0.5 text-xs text-muted-foreground/75">
                            {summary.processing_periods} pay period{summary.processing_periods !== 1 ? 's' : ''} awaiting approval
                        </p>
                    </div>
                    <ChevronRight className="h-4 w-4 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5" />
                </Link>
            ) : null}

            {/* Status Metrics */}
            <SectionLabel icon={BarChart3} label="Pay period status" />
            <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <MetricCard
                    title="Draft"
                    value={summary.draft_periods.toString()}
                    hint="Ready to generate payroll"
                    icon={FileText}
                    iconColor="text-slate-400"
                    iconBg="bg-slate-500/10 border-slate-500/20"
                    accent="border-slate-500/20 hover:border-slate-500/30"
                    href={can.view_periods ? payrollIndex.url({ query: { status: 'draft' } }) : undefined}
                />
                <MetricCard
                    title="Processing"
                    value={summary.processing_periods.toString()}
                    hint="Awaiting approval"
                    icon={Clock}
                    iconColor="text-amber-400"
                    iconBg="bg-amber-500/10 border-amber-500/20"
                    accent="border-amber-500/20 hover:border-amber-500/30"
                    href={can.view_periods ? payrollIndex.url({ query: { status: 'processing' } }) : undefined}
                />
                <MetricCard
                    title="Approved"
                    value={summary.approved_periods.toString()}
                    hint="Ready to mark as paid"
                    icon={CheckCircle2}
                    iconColor="text-emerald-400"
                    iconBg="bg-emerald-500/10 border-emerald-500/20"
                    accent="border-emerald-500/20 hover:border-emerald-500/30"
                    href={can.view_periods ? payrollIndex.url({ query: { status: 'approved' } }) : undefined}
                />
                <MetricCard
                    title="Paid"
                    value={summary.paid_periods.toString()}
                    hint="Completed pay periods"
                    icon={CircleDollarSign}
                    iconColor="text-primary"
                    iconBg="bg-primary/10 border-primary/20"
                    accent="border-primary/20 hover:border-primary/30"
                    href={can.view_periods ? payrollIndex.url({ query: { status: 'paid' } }) : undefined}
                />
                <MetricCard
                    title="Employees"
                    value={summary.total_employees_in_payroll.toString()}
                    hint="Active in payroll system"
                    icon={Users}
                    iconColor="text-violet-400"
                    iconBg="bg-violet-500/10 border-violet-500/20"
                    accent="border-violet-500/20 hover:border-violet-500/30"
                />
            </div>

            {/* Trend + Attention */}
            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                {/* Payroll Trend */}
                <Card className="glass-card overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight">
                                    Payroll trend
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Total net salary paid over the last 6 months
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10">
                                <BarChart3 className="h-4 w-4 text-primary" />
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5">
                        {summary.monthly_trend.every((m) => m.total === 0) ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
                                <p className="text-sm font-medium text-muted-foreground/50">
                                    No paid payroll data in the last 6 months
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {summary.monthly_trend.map((month) => (
                                    <div key={month.month} className="flex items-center gap-3">
                                        <span className="w-14 shrink-0 text-right text-[11px] font-medium text-muted-foreground/60">
                                            {month.month}
                                        </span>
                                        <div className="relative flex-1 overflow-hidden rounded-full bg-muted/30 h-7">
                                            <div
                                                className="h-full rounded-full bg-gradient-to-r from-primary/70 to-primary/40 transition-all duration-700"
                                                style={{ width: `${Math.max((month.total / maxTrend) * 100, month.total > 0 ? 2 : 0)}%` }}
                                            />
                                            {month.total > 0 ? (
                                                <span className="absolute inset-y-0 left-3 flex items-center text-[11px] font-bold text-foreground/80">
                                                    {formatCurrency(month.total)}
                                                </span>
                                            ) : (
                                                <span className="absolute inset-y-0 left-3 flex items-center text-[11px] text-muted-foreground/40">
                                                    —
                                                </span>
                                            )}
                                        </div>
                                        <span className="w-8 shrink-0 text-right text-[11px] text-muted-foreground/50">
                                            {month.count > 0 ? `${month.count}` : ''}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}

                        {summary.last_paid_period_name !== null && summary.last_paid_period_total !== null ? (
                            <div className="mt-5 rounded-xl border border-border/60 bg-muted/10 px-4 py-3 dark:border-white/5">
                                <p className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground/50">
                                    Last paid period
                                </p>
                                <p className="mt-1 text-lg font-black tabular-nums">
                                    {formatCurrency(summary.last_paid_period_total)}
                                </p>
                                <p className="text-xs text-muted-foreground/60">{summary.last_paid_period_name}</p>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>

                {/* Attention Required */}
                <Card className="glass-card overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
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
                            {can.view_periods ? (
                                <Button variant="outline" size="sm" className="h-8 rounded-lg text-xs" asChild>
                                    <Link href={payrollIndex.url()}>View all</Link>
                                </Button>
                            ) : null}
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
                                    href={payrollIndex.url({ query: { status: item.type === 'pending_approval' ? 'processing' : item.type === 'approved' ? 'approved' : 'draft' } })}
                                    className="group flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/[0.01] dark:hover:border-white/10"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="truncate text-sm font-semibold text-foreground/80 group-hover:text-primary">
                                                {item.title}
                                            </p>
                                            <Badge variant={SEVERITY_BADGE[item.severity] ?? 'secondary'}>
                                                {TYPE_LABELS[item.type] ?? item.type}
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

            {/* Advanced Analytics */}
            {summary.salary_breakdown || summary.department_costs ? (
                <>
                    <SectionLabel icon={BarChart3} label="Analytics (Last Paid Period)" />
                    <div className="mb-6 grid gap-6 lg:grid-cols-3">
                        {/* Salary Breakdown */}
                        {summary.salary_breakdown ? (
                            <Card className="glass-card overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
                                <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Salary components
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Basic vs Allowances vs Deductions
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col items-center justify-center pt-5">
                                    <div className="h-48 w-full">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <PieChart>
                                                <Pie
                                                    data={[
                                                        { name: 'Basic', value: summary.salary_breakdown.basic, color: '#3b82f6' },
                                                        { name: 'Allowances', value: summary.salary_breakdown.allowances, color: '#10b981' },
                                                        { name: 'Deductions', value: summary.salary_breakdown.deductions, color: '#ef4444' },
                                                    ]}
                                                    cx="50%"
                                                    cy="50%"
                                                    innerRadius={50}
                                                    outerRadius={70}
                                                    paddingAngle={3}
                                                    dataKey="value"
                                                >
                                                    {['#3b82f6', '#10b981', '#ef4444'].map((color, index) => (
                                                        <Cell key={`cell-${index}`} fill={color} />
                                                    ))}
                                                </Pie>
                                                <Tooltip
                                                    formatter={(value: number) => formatCurrency(value)}
                                                    contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1)' }}
                                                />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="mt-2 flex w-full justify-between px-2 text-xs text-muted-foreground">
                                        <div className="flex items-center gap-1.5"><div className="h-2 w-2 rounded-full bg-blue-500" /> Basic</div>
                                        <div className="flex items-center gap-1.5"><div className="h-2 w-2 rounded-full bg-emerald-500" /> Allowances</div>
                                        <div className="flex items-center gap-1.5"><div className="h-2 w-2 rounded-full bg-red-500" /> Deductions</div>
                                    </div>
                                </CardContent>
                            </Card>
                        ) : null}

                        {/* Category Split */}
                        {summary.category_split ? (
                            <Card className="glass-card overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
                                <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Payroll category
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Crew vs Office split
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col items-center justify-center pt-5">
                                    <div className="h-48 w-full">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <PieChart>
                                                <Pie
                                                    data={summary.category_split.map((c, i) => ({ ...c, color: ['#8b5cf6', '#f59e0b', '#ec4899'][i % 3] }))}
                                                    cx="50%"
                                                    cy="50%"
                                                    innerRadius={50}
                                                    outerRadius={70}
                                                    paddingAngle={3}
                                                    dataKey="total"
                                                >
                                                    {summary.category_split.map((c, i) => (
                                                        <Cell key={`cell-${i}`} fill={['#8b5cf6', '#f59e0b', '#ec4899'][i % 3]} />
                                                    ))}
                                                </Pie>
                                                <Tooltip
                                                    formatter={(value: number) => formatCurrency(value)}
                                                    contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1)' }}
                                                />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="mt-2 flex w-full justify-center gap-4 px-2 text-xs text-muted-foreground">
                                        {summary.category_split.map((c, i) => (
                                            <div key={c.name} className="flex items-center gap-1.5">
                                                <div className="h-2 w-2 rounded-full" style={{ backgroundColor: ['#8b5cf6', '#f59e0b', '#ec4899'][i % 3] }} />
                                                {c.name}
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        ) : null}

                        {/* Department Cost */}
                        {summary.department_costs ? (
                            <Card className="glass-card lg:col-span-1 overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
                                <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                    <CardTitle className="text-base font-bold tracking-tight">
                                        Department cost
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Payroll cost by department
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="pt-5">
                                    <div className="h-[216px] w-full">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={summary.department_costs.slice(0, 5)} layout="vertical" margin={{ top: 0, right: 0, left: 0, bottom: 0 }}>
                                                <XAxis type="number" hide />
                                                <YAxis dataKey="name" type="category" width={80} tick={{ fontSize: 11, fill: '#888888' }} axisLine={false} tickLine={false} />
                                                <Tooltip
                                                    formatter={(value: number) => formatCurrency(value)}
                                                    cursor={{ fill: 'rgba(0,0,0,0.05)' }}
                                                    contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1)' }}
                                                />
                                                <Bar dataKey="total" fill="#3b82f6" radius={[0, 4, 4, 0]} barSize={20} />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </>
            ) : null}
        </Main>
    );
}

function SectionLabel({
    icon: Icon,
    label,
}: {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
}): ReactElement {
    return (
        <div className="mb-4 flex select-none items-center gap-2">
            <Icon className="h-3.5 w-3.5 text-muted-foreground/50" />
            <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/50">
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
}: {
    title: string;
    value: string;
    hint: string;
    icon: React.ComponentType<{ className?: string }>;
    iconColor?: string;
    iconBg?: string;
    accent?: string;
    href?: string;
}): ReactElement {
    const content = (
        <Card
            className={cn(
                'group glass-card gap-0 overflow-hidden p-0 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl',
                accent,
                href && 'cursor-pointer',
            )}
        >
            <CardHeader className="relative flex flex-row items-center justify-between space-y-0 px-5 pb-1 pt-4">
                <CardTitle className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground/85">
                    {title}
                </CardTitle>
                <div className={cn('flex h-9 w-9 items-center justify-center rounded-xl border', iconBg)}>
                    <Icon className={cn('h-4 w-4', iconColor)} />
                </div>
            </CardHeader>
            <CardContent className="relative px-5 pb-4 pt-0">
                <div className="text-3xl font-black tracking-tight">{value}</div>
                <p className="mt-1.5 text-xs text-muted-foreground/80">{hint}</p>
            </CardContent>
        </Card>
    );

    if (href) {
        return <Link href={href}>{content}</Link>;
    }

    return content;
}
