import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    CalendarCheck2,
    CalendarDays,
    CheckCircle2,
    ChevronRight,
    Clock,
    LayoutDashboard,
    Timer,
    TrendingUp,
    Users,
    XCircle,
} from 'lucide-react';
import type { ReactElement } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
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
import { index as calendarIndex } from '@/routes/attendance/calendar';
import { index as leaveRequestsIndex } from '@/routes/attendance/leave-requests';
import { index as recordsIndex } from '@/routes/attendance/records';

/* ─────────────────────── types ─────────────────────── */

type MonthlyTrend = {
    month: string;
    total: number;
    present: number;
    absent: number;
    late: number;
    avg_hours: number;
    total_overtime: number;
    late_minutes: number;
};

type LeaveMonthlyTrend = {
    month: string;
    pending: number;
    approved: number;
    total_days: number;
};

type RecentPendingLeave = {
    id: number;
    employee_name: string;
    leave_type: string | null;
    start_date: string | null;
    end_date: string | null;
    total_days: string | number;
    created_at: string | null;
};

type StatusBreakdownItem = {
    name: string;
    count: number;
};

type OverviewSummary = {
    this_month_total: number;
    this_month_present: number;
    this_month_absent: number;
    this_month_late: number;
    this_month_half_day: number;
    this_month_holiday: number;
    this_month_weekend: number;
    this_month_avg_hours: number | null;
    this_month_total_overtime_hours: number;
    this_month_total_late_minutes: number;
    ytd_total_records: number;
    ytd_total_overtime_hours: number;
    ytd_total_late_minutes: number;
    source_breakdown: { manual: number; biometric: number; mobile: number };
    status_breakdown: StatusBreakdownItem[];
    monthly_trend: MonthlyTrend[];
    leave_pending: number;
    leave_approved: number;
    leave_rejected: number;
    leave_cancelled: number;
    leave_approved_days_this_month: number;
    leave_approved_days_ytd: number;
    leave_monthly_trend: LeaveMonthlyTrend[];
    recent_pending_leaves: RecentPendingLeave[];
};

type CanPermissions = {
    view_records: boolean;
    view_leave_requests: boolean;
    approve_leave_requests: boolean;
    view_calendar: boolean;
};

export type AttendanceOverviewProps = {
    summary: OverviewSummary;
    can: CanPermissions;
};

/* ─────────────────────── constants ─────────────────────── */

const STATUS_COLORS: Record<string, string> = {
    Present: '#22c55e',
    Absent: '#ef4444',
    Late: '#f59e0b',
    'Half day': '#a78bfa',
    Holiday: '#60a5fa',
    Weekend: '#94a3b8',
};

const TOOLTIP_STYLE = {
    borderRadius: '12px',
    border: '1px solid hsl(var(--border))',
    background: 'hsl(var(--card))',
    boxShadow: '0 10px 25px -5px rgba(0,0,0,0.15)',
    fontSize: '12px',
    color: 'hsl(var(--foreground))',
};

/* ─────────────────────── small sub-components ─────────────────────── */

function SectionLabel({
    icon: Icon,
    label,
}: {
    icon: React.FC<{ className?: string }>;
    label: string;
}) {
    return (
        <div className="mb-4 flex items-center gap-2">
            <Icon className="h-3.5 w-3.5 text-primary" />
            <span className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/60 uppercase">
                {label}
            </span>
            <div className="h-px flex-1 bg-border/40" />
        </div>
    );
}

function MetricCard({
    title,
    value,
    hint,
    icon: Icon,
    iconColor,
    iconBg,
    accent,
    href,
    valueSmall,
}: {
    title: string;
    value: string;
    hint: string;
    icon: React.FC<{ className?: string }>;
    iconColor: string;
    iconBg: string;
    accent: string;
    href?: string;
    valueSmall?: boolean;
}) {
    const inner = (
        <div
            className={cn(
                'glass-card group relative flex flex-col gap-3 rounded-2xl border p-5 transition-all duration-300',
                accent,
                href && 'cursor-pointer hover:-translate-y-0.5 hover:shadow-lg',
            )}
        >
            <div className="flex items-start justify-between">
                <div
                    className={cn(
                        'flex h-9 w-9 items-center justify-center rounded-xl border',
                        iconBg,
                    )}
                >
                    <Icon className={cn('h-4 w-4', iconColor)} />
                </div>
                {href && (
                    <ChevronRight className="h-3.5 w-3.5 text-muted-foreground/30 transition-transform group-hover:translate-x-0.5" />
                )}
            </div>
            <div>
                <p
                    className={cn(
                        'font-extrabold tracking-tight',
                        valueSmall ? 'text-lg' : 'text-2xl',
                    )}
                >
                    {value}
                </p>
                <p className="mt-0.5 text-[11px] font-semibold text-muted-foreground/60">
                    {title}
                </p>
                <p className="mt-1 text-[10px] text-muted-foreground/40">
                    {hint}
                </p>
            </div>
        </div>
    );

    if (href) {
        return <Link href={href}>{inner}</Link>;
    }

    return inner;
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
    color: 'blue' | 'emerald' | 'amber' | 'violet';
    icon: React.FC<{ className?: string }>;
}) {
    const colors = {
        blue: 'border-blue-500/20 bg-blue-500/5 text-blue-400',
        emerald: 'border-emerald-500/20 bg-emerald-500/5 text-emerald-400',
        amber: 'border-amber-500/20 bg-amber-500/5 text-amber-400',
        violet: 'border-violet-500/20 bg-violet-500/5 text-violet-400',
    };

    return (
        <Card className={cn('rounded-2xl border', colors[color])}>
            <CardHeader className="pb-2">
                <CardDescription className="flex items-center gap-1.5 text-current/70">
                    <Icon className="h-3.5 w-3.5" />
                    {label}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <p className="text-2xl font-extrabold tracking-tight">{value}</p>
                <p className="mt-1 text-[11px] text-current/50">{sub}</p>
            </CardContent>
        </Card>
    );
}

/* ─────────────────────── main component ─────────────────────── */

export function AttendanceOverviewContent({
    summary,
    can,
}: AttendanceOverviewProps): ReactElement {
    const today = new Date().toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const currentMonth = new Date().toLocaleString('en-US', {
        month: 'long',
        year: 'numeric',
    });

    const hasPendingLeaves = summary.leave_pending > 0;
    const hasTrendData = summary.monthly_trend.some((m) => m.total > 0);
    const hasLeaveTrendData = summary.leave_monthly_trend.some(
        (m) => m.approved > 0 || m.pending > 0,
    );

    const avgHoursDisplay =
        summary.this_month_avg_hours !== null
            ? `${summary.this_month_avg_hours}h`
            : '—';

    const lateHours = Math.floor(summary.this_month_total_late_minutes / 60);
    const lateMins = summary.this_month_total_late_minutes % 60;
    const lateDisplay =
        summary.this_month_total_late_minutes > 0
            ? lateHours > 0
                ? `${lateHours}h ${lateMins}m`
                : `${lateMins}m`
            : '0m';

    const sourceData = [
        { name: 'Manual', value: summary.source_breakdown.manual },
        { name: 'Biometric', value: summary.source_breakdown.biometric },
        { name: 'Mobile', value: summary.source_breakdown.mobile },
    ].filter((s) => s.value > 0);

    return (
        <Main>
            {/* ── Header ── */}
            <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <LayoutDashboard className="h-4 w-4 text-primary" />
                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                            Attendance
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
                    {can.view_calendar && (
                        <Button
                            variant="outline"
                            className="rounded-xl glass-card"
                            asChild
                        >
                            <Link href={calendarIndex.url()}>
                                <CalendarDays className="mr-2 h-4 w-4" />
                                Calendar
                            </Link>
                        </Button>
                    )}
                    {can.view_leave_requests && (
                        <Button
                            variant="outline"
                            className="rounded-xl glass-card"
                            asChild
                        >
                            <Link href={leaveRequestsIndex.url()}>
                                <CalendarCheck2 className="mr-2 h-4 w-4" />
                                Leave requests
                            </Link>
                        </Button>
                    )}
                    {can.view_records && (
                        <Button className="rounded-xl" asChild>
                            <Link href={recordsIndex.url()}>
                                <BarChart3 className="mr-2 h-4 w-4" />
                                Records
                            </Link>
                        </Button>
                    )}
                </div>
            </div>

            {/* ── Urgent alert: pending leave requests ── */}
            {hasPendingLeaves && can.approve_leave_requests && (
                <Link
                    href={leaveRequestsIndex.url({ query: { status: 'pending' } })}
                    className="group mb-8 flex items-center gap-3 rounded-2xl border border-amber-500/25 bg-amber-500/5 px-5 py-4 transition-all duration-300 hover:border-amber-500/40 hover:bg-amber-500/10"
                >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-amber-500/20 bg-amber-500/10">
                        <AlertTriangle className="h-4 w-4 text-amber-400" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-bold text-amber-400">
                            Approval required
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground/75">
                            {summary.leave_pending} leave request
                            {summary.leave_pending !== 1 ? 's' : ''} awaiting
                            approval
                        </p>
                    </div>
                    <ChevronRight className="h-4 w-4 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5" />
                </Link>
            )}

            {/* ── Section: This month KPIs ── */}
            <SectionLabel icon={BarChart3} label={`Attendance – ${currentMonth}`} />
            <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                <MetricCard
                    title="Total records"
                    value={summary.this_month_total.toString()}
                    hint="All statuses this month"
                    icon={Users}
                    iconColor="text-primary"
                    iconBg="bg-primary/10 border-primary/20"
                    accent="border-primary/20 hover:border-primary/30"
                    href={can.view_records ? recordsIndex.url() : undefined}
                />
                <MetricCard
                    title="Present"
                    value={summary.this_month_present.toString()}
                    hint="On time"
                    icon={CheckCircle2}
                    iconColor="text-emerald-400"
                    iconBg="bg-emerald-500/10 border-emerald-500/20"
                    accent="border-emerald-500/20 hover:border-emerald-500/30"
                    href={can.view_records ? recordsIndex.url({ query: { status: 'present' } }) : undefined}
                />
                <MetricCard
                    title="Absent"
                    value={summary.this_month_absent.toString()}
                    hint="Not checked in"
                    icon={XCircle}
                    iconColor="text-red-400"
                    iconBg="bg-red-500/10 border-red-500/20"
                    accent="border-red-500/20 hover:border-red-500/30"
                    href={can.view_records ? recordsIndex.url({ query: { status: 'absent' } }) : undefined}
                />
                <MetricCard
                    title="Late"
                    value={summary.this_month_late.toString()}
                    hint="Arrived after shift"
                    icon={Clock}
                    iconColor="text-amber-400"
                    iconBg="bg-amber-500/10 border-amber-500/20"
                    accent="border-amber-500/20 hover:border-amber-500/30"
                    href={can.view_records ? recordsIndex.url({ query: { status: 'late' } }) : undefined}
                />
                <MetricCard
                    title="Avg hours"
                    value={avgHoursDisplay}
                    hint="Average worked / day"
                    icon={Timer}
                    iconColor="text-cyan-400"
                    iconBg="bg-cyan-500/10 border-cyan-500/20"
                    accent="border-cyan-500/20 hover:border-cyan-500/30"
                />
                <MetricCard
                    title="Late time"
                    value={lateDisplay}
                    hint="Total late minutes"
                    icon={AlertTriangle}
                    iconColor="text-orange-400"
                    iconBg="bg-orange-500/10 border-orange-500/20"
                    accent="border-orange-500/20 hover:border-orange-500/30"
                    valueSmall
                />
            </div>

            {/* ── Section: YTD ── */}
            <SectionLabel icon={TrendingUp} label="Year-to-date totals" />
            <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <YtdCard
                    label="Total records"
                    value={summary.ytd_total_records.toString()}
                    sub="All attendance entries"
                    color="blue"
                    icon={Users}
                />
                <YtdCard
                    label="OT hours"
                    value={`${summary.ytd_total_overtime_hours}h`}
                    sub="Total overtime this year"
                    color="emerald"
                    icon={Timer}
                />
                <YtdCard
                    label="Approved leave days"
                    value={summary.leave_approved_days_ytd.toFixed(1)}
                    sub="Approved leave taken"
                    color="violet"
                    icon={CalendarCheck2}
                />
                <YtdCard
                    label="Total late minutes"
                    value={summary.ytd_total_late_minutes.toString()}
                    sub="Accumulated lateness"
                    color="amber"
                    icon={Clock}
                />
            </div>

            {/* ── Charts row: Monthly Trend + Status Breakdown ── */}
            {(hasTrendData || summary.status_breakdown.some((s) => s.count > 0)) && (
                <>
                    <SectionLabel icon={BarChart3} label="Trends & breakdown" />
                    <div className="mb-8 grid gap-6 lg:grid-cols-3">
                        {/* Monthly Trend – 6 months */}
                        {hasTrendData && (
                            <Card className="glass-card col-span-2 rounded-2xl border border-border/50">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-semibold">
                                        Monthly attendance trend
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Records over the last 6 months
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={220}>
                                        <AreaChart
                                            data={summary.monthly_trend}
                                            margin={{ top: 4, right: 8, left: -20, bottom: 0 }}
                                        >
                                            <defs>
                                                <linearGradient
                                                    id="presentGrad"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="5%"
                                                        stopColor="#22c55e"
                                                        stopOpacity={0.25}
                                                    />
                                                    <stop
                                                        offset="95%"
                                                        stopColor="#22c55e"
                                                        stopOpacity={0}
                                                    />
                                                </linearGradient>
                                                <linearGradient
                                                    id="absentGrad"
                                                    x1="0"
                                                    y1="0"
                                                    x2="0"
                                                    y2="1"
                                                >
                                                    <stop
                                                        offset="5%"
                                                        stopColor="#ef4444"
                                                        stopOpacity={0.2}
                                                    />
                                                    <stop
                                                        offset="95%"
                                                        stopColor="#ef4444"
                                                        stopOpacity={0}
                                                    />
                                                </linearGradient>
                                            </defs>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                stroke="hsl(var(--border))"
                                                opacity={0.4}
                                            />
                                            <XAxis
                                                dataKey="month"
                                                tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }}
                                                tickLine={false}
                                                axisLine={false}
                                            />
                                            <YAxis
                                                tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }}
                                                tickLine={false}
                                                axisLine={false}
                                            />
                                            <Tooltip contentStyle={TOOLTIP_STYLE} />
                                            <Area
                                                type="monotone"
                                                dataKey="present"
                                                name="Present"
                                                stroke="#22c55e"
                                                strokeWidth={2}
                                                fill="url(#presentGrad)"
                                                dot={false}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="absent"
                                                name="Absent"
                                                stroke="#ef4444"
                                                strokeWidth={2}
                                                fill="url(#absentGrad)"
                                                dot={false}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="late"
                                                name="Late"
                                                stroke="#f59e0b"
                                                strokeWidth={1.5}
                                                fill="none"
                                                dot={false}
                                                strokeDasharray="4 2"
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        )}

                        {/* Status Breakdown Pie */}
                        {summary.status_breakdown.some((s) => s.count > 0) && (
                            <Card className="glass-card rounded-2xl border border-border/50">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-semibold">
                                        Status breakdown
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        {currentMonth}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={160}>
                                        <PieChart>
                                            <Pie
                                                data={summary.status_breakdown.filter(
                                                    (s) => s.count > 0,
                                                )}
                                                dataKey="count"
                                                nameKey="name"
                                                cx="50%"
                                                cy="50%"
                                                innerRadius={45}
                                                outerRadius={68}
                                                strokeWidth={0}
                                            >
                                                {summary.status_breakdown
                                                    .filter((s) => s.count > 0)
                                                    .map((entry) => (
                                                        <Cell
                                                            key={entry.name}
                                                            fill={
                                                                STATUS_COLORS[
                                                                    entry.name
                                                                ] ?? '#94a3b8'
                                                            }
                                                        />
                                                    ))}
                                            </Pie>
                                            <Tooltip contentStyle={TOOLTIP_STYLE} />
                                        </PieChart>
                                    </ResponsiveContainer>
                                    <div className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5">
                                        {summary.status_breakdown
                                            .filter((s) => s.count > 0)
                                            .map((s) => (
                                                <div
                                                    key={s.name}
                                                    className="flex items-center gap-1.5"
                                                >
                                                    <span
                                                        className="h-2 w-2 rounded-full"
                                                        style={{
                                                            background:
                                                                STATUS_COLORS[
                                                                    s.name
                                                                ] ?? '#94a3b8',
                                                        }}
                                                    />
                                                    <span className="text-[10px] text-muted-foreground/70">
                                                        {s.name}: {s.count}
                                                    </span>
                                                </div>
                                            ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </>
            )}

            {/* ── Section: Leave Requests ── */}
            <SectionLabel icon={CalendarCheck2} label="Leave requests" />
            <div className="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    href={
                        can.view_leave_requests
                            ? leaveRequestsIndex.url({ query: { status: 'pending' } })
                            : '#'
                    }
                    className="group glass-card flex flex-col gap-3 rounded-2xl border border-amber-500/20 p-5 transition-all hover:-translate-y-0.5 hover:border-amber-500/35 hover:shadow-lg"
                >
                    <div className="flex items-center justify-between">
                        <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-amber-500/20 bg-amber-500/10">
                            <Clock className="h-4 w-4 text-amber-400" />
                        </div>
                        <ChevronRight className="h-3.5 w-3.5 text-muted-foreground/30 transition-transform group-hover:translate-x-0.5" />
                    </div>
                    <div>
                        <p className="text-2xl font-extrabold tracking-tight">
                            {summary.leave_pending}
                        </p>
                        <p className="text-[11px] font-semibold text-muted-foreground/60">
                            Pending
                        </p>
                    </div>
                </Link>
                <Link
                    href={
                        can.view_leave_requests
                            ? leaveRequestsIndex.url({ query: { status: 'approved' } })
                            : '#'
                    }
                    className="group glass-card flex flex-col gap-3 rounded-2xl border border-emerald-500/20 p-5 transition-all hover:-translate-y-0.5 hover:border-emerald-500/35 hover:shadow-lg"
                >
                    <div className="flex items-center justify-between">
                        <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-emerald-500/20 bg-emerald-500/10">
                            <CheckCircle2 className="h-4 w-4 text-emerald-400" />
                        </div>
                        <ChevronRight className="h-3.5 w-3.5 text-muted-foreground/30 transition-transform group-hover:translate-x-0.5" />
                    </div>
                    <div>
                        <p className="text-2xl font-extrabold tracking-tight">
                            {summary.leave_approved}
                        </p>
                        <p className="text-[11px] font-semibold text-muted-foreground/60">
                            Approved
                        </p>
                    </div>
                </Link>
                <Link
                    href={
                        can.view_leave_requests
                            ? leaveRequestsIndex.url({ query: { status: 'rejected' } })
                            : '#'
                    }
                    className="group glass-card flex flex-col gap-3 rounded-2xl border border-red-500/20 p-5 transition-all hover:-translate-y-0.5 hover:border-red-500/35 hover:shadow-lg"
                >
                    <div className="flex items-center justify-between">
                        <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-red-500/20 bg-red-500/10">
                            <XCircle className="h-4 w-4 text-red-400" />
                        </div>
                        <ChevronRight className="h-3.5 w-3.5 text-muted-foreground/30 transition-transform group-hover:translate-x-0.5" />
                    </div>
                    <div>
                        <p className="text-2xl font-extrabold tracking-tight">
                            {summary.leave_rejected}
                        </p>
                        <p className="text-[11px] font-semibold text-muted-foreground/60">
                            Rejected
                        </p>
                    </div>
                </Link>
                <div className="glass-card flex flex-col gap-3 rounded-2xl border border-slate-500/20 p-5">
                    <div className="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-500/20 bg-slate-500/10">
                        <CalendarCheck2 className="h-4 w-4 text-slate-400" />
                    </div>
                    <div>
                        <p className="text-2xl font-extrabold tracking-tight">
                            {summary.leave_approved_days_this_month.toFixed(1)}
                        </p>
                        <p className="text-[11px] font-semibold text-muted-foreground/60">
                            Days approved this month
                        </p>
                    </div>
                </div>
            </div>

            {/* ── Leave + Source charts row ── */}
            {(hasLeaveTrendData || sourceData.length > 0) && (
                <div className="mb-8 grid gap-6 lg:grid-cols-2">
                    {/* Leave Monthly Trend */}
                    {hasLeaveTrendData && (
                        <Card className="glass-card rounded-2xl border border-border/50">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-semibold">
                                    Leave requests trend
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Last 6 months
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ResponsiveContainer width="100%" height={200}>
                                    <BarChart
                                        data={summary.leave_monthly_trend}
                                        margin={{ top: 4, right: 8, left: -20, bottom: 0 }}
                                    >
                                        <CartesianGrid
                                            strokeDasharray="3 3"
                                            stroke="hsl(var(--border))"
                                            opacity={0.4}
                                        />
                                        <XAxis
                                            dataKey="month"
                                            tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }}
                                            tickLine={false}
                                            axisLine={false}
                                        />
                                        <YAxis
                                            tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }}
                                            tickLine={false}
                                            axisLine={false}
                                        />
                                        <Tooltip contentStyle={TOOLTIP_STYLE} />
                                        <Bar
                                            dataKey="approved"
                                            name="Approved"
                                            fill="#22c55e"
                                            radius={[4, 4, 0, 0]}
                                            opacity={0.85}
                                        />
                                        <Bar
                                            dataKey="pending"
                                            name="Pending"
                                            fill="#f59e0b"
                                            radius={[4, 4, 0, 0]}
                                            opacity={0.85}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </CardContent>
                        </Card>
                    )}

                    {/* Source breakdown */}
                    {sourceData.length > 0 && (
                        <Card className="glass-card rounded-2xl border border-border/50">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-semibold">
                                    Check-in source
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    {currentMonth}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ResponsiveContainer width="100%" height={200}>
                                    <BarChart
                                        data={sourceData}
                                        layout="vertical"
                                        margin={{ top: 4, right: 16, left: 10, bottom: 0 }}
                                    >
                                        <CartesianGrid
                                            strokeDasharray="3 3"
                                            stroke="hsl(var(--border))"
                                            opacity={0.4}
                                            horizontal={false}
                                        />
                                        <XAxis
                                            type="number"
                                            tick={{ fontSize: 10, fill: 'hsl(var(--muted-foreground))' }}
                                            tickLine={false}
                                            axisLine={false}
                                        />
                                        <YAxis
                                            type="category"
                                            dataKey="name"
                                            tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }}
                                            tickLine={false}
                                            axisLine={false}
                                            width={60}
                                        />
                                        <Tooltip contentStyle={TOOLTIP_STYLE} />
                                        <Bar
                                            dataKey="value"
                                            name="Records"
                                            radius={[0, 4, 4, 0]}
                                        >
                                            {sourceData.map((entry, index) => (
                                                <Cell
                                                    key={`cell-${index}`}
                                                    fill={
                                                        ['#818cf8', '#22d3ee', '#34d399'][
                                                            index % 3
                                                        ]
                                                    }
                                                    opacity={0.85}
                                                />
                                            ))}
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            </CardContent>
                        </Card>
                    )}
                </div>
            )}

            {/* ── Recent pending leave requests ── */}
            {summary.recent_pending_leaves.length > 0 && can.view_leave_requests && (
                <>
                    <SectionLabel icon={Clock} label="Recent pending leave requests" />
                    <Card className="glass-card mb-8 rounded-2xl border border-border/50">
                        <CardContent className="p-0">
                            <div className="divide-y divide-border/40">
                                {summary.recent_pending_leaves.map((lr) => (
                                    <Link
                                        key={lr.id}
                                        href={`/attendance/leave-requests/${lr.id}`}
                                        className="group flex items-center gap-4 px-5 py-4 transition-colors hover:bg-muted/30"
                                    >
                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-500/10">
                                            <Clock className="h-3.5 w-3.5 text-amber-400" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold">
                                                {lr.employee_name}
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground/60">
                                                {lr.leave_type ?? 'Leave'} &middot;{' '}
                                                {lr.start_date} – {lr.end_date}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-3">
                                            <Badge variant="warning" className="text-[10px]">
                                                {lr.total_days}{' '}
                                                {Number(lr.total_days) === 1 ? 'day' : 'days'}
                                            </Badge>
                                            <ChevronRight className="h-3.5 w-3.5 text-muted-foreground/30 transition-transform group-hover:translate-x-0.5" />
                                        </div>
                                    </Link>
                                ))}
                            </div>
                            {summary.leave_pending > summary.recent_pending_leaves.length && (
                                <div className="border-t border-border/40 px-5 py-3">
                                    <Link
                                        href={leaveRequestsIndex.url({ query: { status: 'pending' } })}
                                        className="text-xs font-semibold text-primary hover:underline"
                                    >
                                        View all {summary.leave_pending} pending requests →
                                    </Link>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </>
            )}
        </Main>
    );
}
