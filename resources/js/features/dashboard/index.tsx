import { Link, usePoll } from '@inertiajs/react';
import {
    Users,
    UserPlus,
    UserX,
    UserCheck,
    FileText,
    ShieldCheck,
    AlertTriangle,
    Clock,
    Plus,
    LayoutGrid,
    ArrowUpRight,
    Building2,
    Link2,
    Layers,
    BarChart3,
    CalendarDays,
    Sparkles,
    ChevronRight,
    LogIn,
    LogOut,
    Radio,
    UserRoundCheck,
} from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';
import { Main } from '@/components/layout/main';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { RecordStatusBadge } from '@/features/attendance/records/components/record-status-badge';
import { AttendanceTrendChart } from '@/features/dashboard/charts/attendance-trend-chart';
import { DistributionBarChart } from '@/features/dashboard/charts/distribution-bar-chart';
import { DocumentHealthChart } from '@/features/dashboard/charts/document-health-chart';
import { WorkforceTrendChart } from '@/features/dashboard/charts/workforce-trend-chart';
import type { DashboardProps } from '@/features/dashboard/dashboard-types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { documents, employees } from '@/routes/organization';
import { show as showEmployee } from '@/routes/organization/employees';

export type { DashboardProps } from '@/features/dashboard/dashboard-types';

/** Returns initials (up to 2 chars) from a full name. */
function getInitials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
}

/** Stable hue based on a string — for avatar background colours. */
function nameToHue(name: string): number {
    let hash = 0;

    for (let i = 0; i < name.length; i++) {
        hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }

    return Math.abs(hash) % 360;
}

function ChartSkeleton({ className }: { className?: string }): ReactElement {
    return (
        <div
            className={cn('space-y-3', className)}
            role="status"
            aria-live="polite"
            aria-label="Loading chart"
        >
            <Skeleton className="h-4 w-1/3" />
            <Skeleton className="h-48 w-full rounded-xl" />
        </div>
    );
}

function ListSkeleton({ rows = 4 }: { rows?: number }): ReactElement {
    return (
        <div
            className="space-y-2"
            role="status"
            aria-live="polite"
            aria-label="Loading list"
        >
            {Array.from({ length: rows }).map((_, index) => (
                <Skeleton key={index} className="h-14 w-full rounded-xl" />
            ))}
        </div>
    );
}

function DeferredCard({
    title,
    description,
    icon,
    iconColor,
    iconBg,
    loading,
    children,
    headerAside,
}: {
    title: string;
    description: string;
    icon: React.ComponentType<{ className?: string }>;
    iconColor: string;
    iconBg: string;
    loading: boolean;
    children: ReactNode;
    headerAside?: ReactNode;
}): ReactElement {
    const Icon = icon;

    return (
        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
            <CardHeader className="border-b border-border/60 bg-muted/5 pb-4 dark:border-white/5 dark:bg-white/[0.01]">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                            {title}
                        </CardTitle>
                        <CardDescription className="mt-0.5 text-xs font-medium text-muted-foreground/60">
                            {description}
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-2">
                        {headerAside}
                        <div
                            className={cn(
                                'flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border',
                                iconBg,
                            )}
                        >
                            <Icon className={cn('h-4 w-4', iconColor)} />
                        </div>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="pt-5">
                {loading ? <ChartSkeleton /> : children}
            </CardContent>
        </Card>
    );
}

export function DashboardContent({
    document_compliance: documentCompliance,
    employee_analytics: employeeAnalytics,
    workforce_trends: workforceTrends,
    employees_by_department: employeesByDepartment,
    employees_by_branch: employeesByBranch,
    document_health: documentHealth,
    organization_snapshot: organizationSnapshot,
    recent_hires: recentHires,
    attendance_analytics: attendanceAnalytics,
}: DashboardProps): ReactElement {
    usePoll(60_000, {
        only: [
            'document_compliance',
            'employee_analytics',
            'recent_hires',
            'attendance_analytics',
            'workforce_trends',
            'employees_by_department',
            'employees_by_branch',
        ],
    });

    const workforceTrendsLoading = workforceTrends === undefined;
    const departmentLoading = employeesByDepartment === undefined;
    const branchLoading = employeesByBranch === undefined;
    const recentHiresLoading = recentHires === undefined;
    const resolvedRecentHires = recentHires ?? [];
    const resolvedDepartment = employeesByDepartment ?? [];
    const resolvedBranch = employeesByBranch ?? [];
    const resolvedWorkforceTrends = workforceTrends ?? [];

    const placeholder = (key: string) =>
        `${dashboard.url()}?module=${encodeURIComponent(key)}`;

    const employeeActiveRate =
        employeeAnalytics.total > 0
            ? Math.round(
                  (employeeAnalytics.active / employeeAnalytics.total) * 100,
              )
            : 0;

    const today = new Date().toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const hasUrgentItems =
        documentCompliance.expired > 0 || documentCompliance.expiring_7 > 0;

    return (
        <Main>
            {/* ── Header ─────────────────────────────────────────────── */}
            <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <span className="flex h-2 w-2 animate-pulse rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]" />
                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                            Live · Real-time Intelligence
                        </span>
                    </div>
                    <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">
                        HR Dashboard
                    </h1>
                    <p className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground/60">
                        <CalendarDays className="h-3.5 w-3.5" />
                        {today}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <Button
                        variant="outline"
                        className="rounded-xl glass-card hover:bg-muted/80 dark:hover:bg-white/10"
                        asChild
                    >
                        <Link href={employees.url()}>
                            <LayoutGrid className="mr-2 h-4 w-4" />
                            Directory
                        </Link>
                    </Button>
                    <Button
                        className="rounded-xl shadow-lg shadow-primary/25 hover:shadow-primary/30"
                        asChild
                    >
                        <a href={placeholder('quick-actions.create-employee')}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Employee
                        </a>
                    </Button>
                </div>
            </div>

            {/* ── Alert banner (urgent items) ─────────────────────────── */}
            {hasUrgentItems && (
                <Link
                    href={documents.url({ query: { expiry: 'expired' } })}
                    className="group mb-8 flex items-center gap-3 rounded-2xl border border-red-500/25 bg-red-500/5 px-5 py-4 transition-all duration-300 hover:-translate-y-0.5 hover:border-red-500/40 hover:bg-red-500/10 hover:shadow-[0_8px_30px_rgba(239,68,68,0.06)]"
                >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-red-500/20 bg-red-500/10 transition-transform duration-300 group-hover:scale-105">
                        <AlertTriangle className="h-4.5 w-4.5 text-red-400" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-bold text-red-400">
                            Immediate attention required
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground/75">
                            {documentCompliance.expired > 0 &&
                                `${documentCompliance.expired} expired document${documentCompliance.expired !== 1 ? 's' : ''}`}
                            {documentCompliance.expired > 0 &&
                                documentCompliance.expiring_7 > 0 &&
                                ' · '}
                            {documentCompliance.expiring_7 > 0 &&
                                `${documentCompliance.expiring_7} expiring within 7 days`}
                        </p>
                    </div>
                    <ChevronRight className="h-4 w-4 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5" />
                </Link>
            )}

            {/* ── Workforce KPIs ─────────────────────────────────────── */}
            <SectionLabel icon={Users} label="Workforce Overview" />
            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Total Employees"
                    value={employeeAnalytics.total.toLocaleString()}
                    hint="All employees on record"
                    icon={Users}
                    gradient="from-primary/10 to-primary/5"
                    iconColor="text-primary"
                    iconBg="bg-primary/10 border-primary/20"
                    accent="border-primary/20 hover:border-primary/30 hover:shadow-primary/5 hover:bg-primary/[0.01]"
                    badge={`${employeeAnalytics.active} active`}
                    badgeVariant="info"
                    href={employees.url()}
                />
                <MetricCard
                    title="Active"
                    value={employeeAnalytics.active.toLocaleString()}
                    hint="Currently employed"
                    icon={UserCheck}
                    gradient="from-emerald-500/10 to-green-500/5"
                    iconColor="text-emerald-400"
                    iconBg="bg-emerald-500/10 border-emerald-500/20"
                    accent="border-emerald-500/20 hover:border-emerald-500/30 hover:shadow-emerald-500/5 hover:bg-emerald-500/[0.01]"
                    badge={`${employeeActiveRate}% of workforce`}
                    badgeVariant="success"
                    href={employees.url({ query: { status: 'active' } })}
                />
                <MetricCard
                    title="New Hires"
                    value={employeeAnalytics.new_hires_this_month.toLocaleString()}
                    hint="Joined this month"
                    icon={UserPlus}
                    gradient="from-accent/10 to-accent/5"
                    iconColor="text-accent"
                    iconBg="bg-accent/10 border-accent/20"
                    accent="border-accent/20 hover:border-accent/30 hover:shadow-accent/5 hover:bg-accent/[0.01]"
                    href={employees.url()}
                />
                <MetricCard
                    title="On Leave / Inactive"
                    value={(
                        employeeAnalytics.on_leave + employeeAnalytics.inactive
                    ).toLocaleString()}
                    hint="On leave or inactive"
                    icon={UserX}
                    gradient="from-amber-500/10 to-orange-500/5"
                    iconColor="text-amber-400"
                    iconBg="bg-amber-500/10 border-amber-500/20"
                    accent="border-amber-500/20 hover:border-amber-500/30 hover:shadow-amber-500/5 hover:bg-amber-500/[0.01]"
                    badge={
                        employeeAnalytics.terminated > 0
                            ? `${employeeAnalytics.terminated} terminated`
                            : undefined
                    }
                    badgeVariant="warning"
                    href={employees.url()}
                />
            </div>

            {/* ── Document KPIs ────────────────────────────────────── */}
            <SectionLabel icon={FileText} label="Document Compliance" />
            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Total Documents"
                    value={documentCompliance.total_documents.toLocaleString()}
                    hint="All employee documents"
                    icon={FileText}
                    gradient="from-sky-500/10 to-cyan-500/5"
                    iconColor="text-sky-400"
                    iconBg="bg-sky-500/10 border-sky-500/20"
                    accent="border-sky-500/20 hover:border-sky-500/30 hover:shadow-sky-500/5 hover:bg-sky-500/[0.01]"
                    badge={`+${documentCompliance.uploaded_this_month} this month`}
                    badgeVariant="info"
                    href={documents.url()}
                />
                <MetricCard
                    title="Compliance Rate"
                    value={`${documentCompliance.compliance_rate}%`}
                    hint="Non-expired document share"
                    icon={ShieldCheck}
                    gradient="from-emerald-500/10 to-teal-500/5"
                    iconColor="text-emerald-400"
                    iconBg="bg-emerald-500/10 border-emerald-500/20"
                    accent="border-emerald-500/20 hover:border-emerald-500/30 hover:shadow-emerald-500/5 hover:bg-emerald-500/[0.01]"
                    badge={`${documentCompliance.avg_per_employee} avg / employee`}
                    badgeVariant="success"
                    href={documents.url()}
                />
                <MetricCard
                    title="Expired"
                    value={documentCompliance.expired.toLocaleString()}
                    hint="Require immediate action"
                    icon={AlertTriangle}
                    gradient={
                        documentCompliance.expired > 0
                            ? 'from-red-500/15 to-rose-500/5'
                            : 'from-muted/40 dark:from-white/5 to-transparent'
                    }
                    iconBg={
                        documentCompliance.expired > 0
                            ? 'bg-red-500/10 border-red-500/20'
                            : 'bg-muted/60 border-border/80 dark:bg-white/5 dark:border-white/10'
                    }
                    accent={
                        documentCompliance.expired > 0
                            ? 'border-red-500/25 hover:border-red-500/40 hover:shadow-red-500/5 hover:bg-red-500/[0.01]'
                            : 'border-border/60 hover:border-border dark:border-white/5 dark:hover:border-white/10'
                    }
                    badgeVariant="destructive"
                    href={documents.url({ query: { expiry: 'expired' } })}
                />
                <MetricCard
                    title="Expiring in 7 Days"
                    value={documentCompliance.expiring_7.toLocaleString()}
                    hint="Urgent renewals needed"
                    icon={Clock}
                    gradient="from-orange-500/10 to-amber-500/5"
                    iconColor="text-orange-400"
                    iconBg="bg-orange-500/10 border-orange-500/20"
                    accent="border-orange-500/20 hover:border-orange-500/30 hover:shadow-orange-500/5 hover:bg-orange-500/[0.01]"
                    href={documents.url({ query: { expiry: 'expiring_7' } })}
                />
            </div>

            {/* ── Attendance KPIs ──────────────────────────────────── */}
            <SectionLabel icon={Radio} label="Attendance Today" />
            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard
                    title="Present Today"
                    value={attendanceAnalytics.present_today.toLocaleString()}
                    hint="Unique check-ins today"
                    icon={UserRoundCheck}
                    gradient="from-emerald-500/10 to-teal-500/5"
                    iconColor="text-emerald-400"
                    iconBg="bg-emerald-500/10 border-emerald-500/20"
                    accent="border-emerald-500/20 hover:border-emerald-500/30 hover:shadow-emerald-500/5 hover:bg-emerald-500/[0.01]"
                    badge={
                        attendanceAnalytics.active_employees > 0
                            ? `${attendanceAnalytics.active_employees} active employees`
                            : undefined
                    }
                    badgeVariant="success"
                    href="/attendance/records"
                />
                <MetricCard
                    title="Check Ins"
                    value={attendanceAnalytics.check_ins_today.toLocaleString()}
                    hint="Door and mobile check-ins"
                    icon={LogIn}
                    gradient="from-sky-500/10 to-blue-500/5"
                    iconColor="text-sky-400"
                    iconBg="bg-sky-500/10 border-sky-500/20"
                    accent="border-sky-500/20 hover:border-sky-500/30 hover:shadow-sky-500/5 hover:bg-sky-500/[0.01]"
                    href="/attendance/records"
                />
                <MetricCard
                    title="Check Outs"
                    value={attendanceAnalytics.check_outs_today.toLocaleString()}
                    hint="Recorded departures today"
                    icon={LogOut}
                    gradient="from-primary/10 to-accent/5"
                    iconColor="text-primary"
                    iconBg="bg-primary/10 border-primary/20"
                    accent="border-primary/20 hover:border-primary/30 hover:shadow-primary/5 hover:bg-primary/[0.01]"
                    href="/attendance/records"
                />
                <MetricCard
                    title="Late Today"
                    value={attendanceAnalytics.late_today.toLocaleString()}
                    hint="Late arrivals today"
                    icon={Clock}
                    gradient="from-amber-500/10 to-orange-500/5"
                    iconColor="text-amber-400"
                    iconBg="bg-amber-500/10 border-amber-500/20"
                    accent="border-amber-500/20 hover:border-amber-500/30 hover:shadow-amber-500/5 hover:bg-amber-500/[0.01]"
                    badge={`${attendanceAnalytics.absent_today} absent`}
                    badgeVariant="destructive"
                    href="/attendance/records"
                />
            </div>

            {/* ── Org snapshot strip ─────────────────────────────────── */}
            <div className="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <OrgSnapshotTile
                    icon={Link2}
                    label="User Accounts"
                    value={employeeAnalytics.with_user_account}
                    sub={`${employeeAnalytics.without_user_account} without`}
                    iconColor="text-sky-400"
                    iconBg="bg-sky-400/10 border-sky-400/20"
                    href={employees.url()}
                />
                <OrgSnapshotTile
                    icon={Layers}
                    label="Departments"
                    value={organizationSnapshot.departments}
                    iconColor="text-primary"
                    iconBg="bg-primary/10 border-primary/20"
                    href={employees.url()}
                />
                <OrgSnapshotTile
                    icon={Building2}
                    label="Branches"
                    value={organizationSnapshot.branches}
                    iconColor="text-teal-400"
                    iconBg="bg-teal-400/10 border-teal-400/20"
                    href={employees.url()}
                />
                <OrgSnapshotTile
                    icon={UserRoundCheck}
                    label="Attendance Records"
                    value={attendanceAnalytics.events_today}
                    sub={`${attendanceAnalytics.present_today} present today`}
                    iconColor="text-cyan-400"
                    iconBg="bg-cyan-400/10 border-cyan-400/20"
                    href="/attendance/records"
                />
            </div>

            {/* ── Workforce trend — full width ───────────────────────── */}
            <div className="mb-6">
                <DeferredCard
                    title="Workforce Trends"
                    description="Headcount, hiring & documents over the last 6 months"
                    icon={BarChart3}
                    iconColor="text-primary"
                    iconBg="border-primary/20 bg-primary/10"
                    loading={workforceTrendsLoading}
                >
                    <WorkforceTrendChart data={resolvedWorkforceTrends} />
                </DeferredCard>
            </div>

            {/* ── Charts row ────────────────────────────────────────── */}
            <div className="mb-6 grid gap-6 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <DeferredCard
                        title="Employees by Department"
                        description="Distribution across your organization structure"
                        icon={Layers}
                        iconColor="text-primary"
                        iconBg="border-primary/20 bg-primary/10"
                        loading={departmentLoading}
                    >
                        <DistributionBarChart data={resolvedDepartment} />
                    </DeferredCard>
                </div>

                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 bg-muted/5 pb-4 dark:border-white/5 dark:bg-white/[0.01]">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Document Health
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs font-medium text-muted-foreground/60">
                                    Expiry status breakdown
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-emerald-500/20 bg-emerald-500/10">
                                <ShieldCheck className="h-4 w-4 text-emerald-400" />
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5">
                        <DocumentHealthChart data={documentHealth} />
                    </CardContent>
                </Card>
            </div>

            {/* ── Branch + recent hires ─────────────────────────────── */}
            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <DeferredCard
                    title="Employees by Branch"
                    description="Headcount per branch location"
                    icon={Building2}
                    iconColor="text-teal-400"
                    iconBg="border-teal-500/20 bg-teal-500/10"
                    loading={branchLoading}
                >
                    <DistributionBarChart
                        data={resolvedBranch}
                        layout="horizontal"
                    />
                </DeferredCard>

                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 bg-muted/5 pb-4 dark:border-white/5 dark:bg-white/[0.01]">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Recent Hires
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs font-medium text-muted-foreground/60">
                                    Latest employees added to the system
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                {!recentHiresLoading &&
                                    resolvedRecentHires.length > 0 && (
                                        <Badge
                                            variant="secondary"
                                            className="border border-border/80 bg-muted/60 px-2 font-bold tabular-nums dark:border-white/5 dark:bg-white/5"
                                        >
                                            {resolvedRecentHires.length}
                                        </Badge>
                                    )}
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-accent/20 bg-accent/10">
                                    <Sparkles className="h-4 w-4 text-accent" />
                                </div>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2 pt-4">
                        {recentHiresLoading ? (
                            <ListSkeleton />
                        ) : resolvedRecentHires.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
                                <div className="flex h-12 w-12 items-center justify-center rounded-2xl border border-dashed border-border bg-muted/20 dark:border-white/10 dark:bg-white/[0.02]">
                                    <Users className="h-5 w-5 text-muted-foreground/30" />
                                </div>
                                <p className="text-sm font-medium text-muted-foreground/50">
                                    No employees on record yet
                                </p>
                            </div>
                        ) : (
                            resolvedRecentHires.map((hire) => {
                                const hue = nameToHue(hire.name);

                                return (
                                    <Link
                                        key={hire.id}
                                        href={showEmployee.url({
                                            employee: hire.id,
                                        })}
                                        className="group flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all duration-300 hover:-translate-y-0.5 hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/[0.01] dark:hover:border-white/10 dark:hover:bg-white/[0.03]"
                                    >
                                        <Avatar className="size-9 shrink-0 shadow-md ring-2 ring-background">
                                            <AvatarFallback
                                                className="text-xs font-bold text-white shadow-xs"
                                                style={{
                                                    background: `linear-gradient(135deg, hsl(${hue} 65% 55%), hsl(${hue} 65% 40%))`,
                                                }}
                                            >
                                                {getInitials(hire.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold text-foreground/80 transition-colors group-hover:text-primary">
                                                {hire.name}
                                            </p>
                                            <p className="mt-0.5 text-[10px] font-bold tracking-wider text-muted-foreground/45 uppercase">
                                                {hire.employee_no} ·{' '}
                                                {hire.hired_at}
                                            </p>
                                        </div>
                                        <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground/45 opacity-0 transition-all duration-300 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100" />
                                    </Link>
                                );
                            })
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* ── Attendance detail ─────────────────────────────────── */}
            <div className="grid gap-6 lg:grid-cols-2">
                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 bg-muted/5 pb-4 dark:border-white/5 dark:bg-white/[0.01]">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Attendance Trends
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs font-medium text-muted-foreground/60">
                                    Check-ins and check-outs over the last 7
                                    days
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-cyan-500/20 bg-cyan-500/10">
                                <Radio className="h-4 w-4 text-cyan-400" />
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5">
                        <AttendanceTrendChart
                            data={attendanceAnalytics.weekly_trends}
                        />
                    </CardContent>
                </Card>

                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 bg-muted/5 pb-4 dark:border-white/5 dark:bg-white/[0.01]">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Recent Attendance
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs font-medium text-muted-foreground/60">
                                    Latest check-ins and check-outs from
                                    employees
                                </CardDescription>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8 rounded-lg text-xs"
                                asChild
                            >
                                <Link href="/attendance/records">View all</Link>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2 pt-4">
                        {attendanceAnalytics.recent_records.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
                                <div className="flex h-12 w-12 items-center justify-center rounded-2xl border border-dashed border-border bg-muted/20 dark:border-white/10 dark:bg-white/[0.02]">
                                    <Clock className="h-5 w-5 text-muted-foreground/30" />
                                </div>
                                <p className="text-sm font-medium text-muted-foreground/50">
                                    No attendance records yet
                                </p>
                            </div>
                        ) : (
                            attendanceAnalytics.recent_records.map((record) => {
                                const displayName =
                                    record.employee_name ?? 'Unknown';

                                const formatTime = (
                                    isoString: string | null,
                                ) => {
                                    if (!isoString) {
                                        return '—';
                                    }

                                    return new Date(
                                        isoString,
                                    ).toLocaleTimeString([], {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    });
                                };

                                const clockInTime = formatTime(record.clock_in);
                                const clockOutTime = formatTime(
                                    record.clock_out,
                                );

                                const content = (
                                    <div className="group flex items-center justify-between gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all duration-300 hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/[0.01] dark:hover:border-white/10 dark:hover:bg-white/[0.03]">
                                        <div className="flex min-w-0 flex-1 items-center gap-3">
                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border/80 bg-muted/60 text-muted-foreground dark:border-white/10 dark:bg-white/5">
                                                <Clock className="h-4 w-4" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold text-foreground/80">
                                                    {displayName}
                                                </p>
                                                <p className="mt-0.5 truncate text-[10px] font-bold tracking-wider text-muted-foreground/45 uppercase">
                                                    {record.date
                                                        ? formatDisplayDate(
                                                              record.date,
                                                          )
                                                        : '—'}
                                                    {record.clock_in
                                                        ? ` · In: ${clockInTime}`
                                                        : ''}
                                                    {record.clock_out
                                                        ? ` · Out: ${clockOutTime}`
                                                        : ''}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="shrink-0">
                                            <RecordStatusBadge
                                                status={record.status}
                                            />
                                        </div>
                                    </div>
                                );

                                if (record.employee_id) {
                                    return (
                                        <Link
                                            key={record.id}
                                            href={showEmployee.url({
                                                employee: record.employee_id,
                                            })}
                                            className="block"
                                        >
                                            {content}
                                        </Link>
                                    );
                                }

                                return <div key={record.id}>{content}</div>;
                            })
                        )}
                    </CardContent>
                </Card>
            </div>
        </Main>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Sub-components
// ─────────────────────────────────────────────────────────────────────────────

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
    gradient = 'from-muted/20 to-transparent',
    accent = 'border-border',
    badge,
    badgeVariant = 'secondary',
    href,
}: {
    title: string;
    value: string;
    hint: string;
    icon?: React.ComponentType<{ className?: string }>;
    iconColor?: string;
    iconBg?: string;
    gradient?: string;
    accent?: string;
    badge?: string;
    badgeVariant?:
        | 'default'
        | 'secondary'
        | 'info'
        | 'success'
        | 'warning'
        | 'destructive'
        | 'outline';
    href?: string;
}): ReactElement {
    const content = (
        <Card
            className={cn(
                'group relative gap-0 overflow-hidden glass-card p-0 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl',
                accent,
                href && 'cursor-pointer',
            )}
        >
            {/* Gradient accent */}
            <div
                className={cn(
                    'absolute inset-x-0 top-0 h-20 bg-gradient-to-b opacity-15 transition-opacity duration-300 group-hover:opacity-25',
                    gradient,
                )}
            />
            <CardHeader className="relative flex flex-row items-center justify-between space-y-0 px-5 pt-4 pb-1">
                <CardTitle className="text-[10px] font-bold tracking-wider text-muted-foreground/85 uppercase transition-colors group-hover:text-foreground/80 dark:text-muted-foreground/60">
                    {title}
                </CardTitle>
                {Icon && (
                    <div
                        className={cn(
                            'flex h-9 w-9 items-center justify-center rounded-xl border transition-all duration-300 group-hover:scale-110 group-hover:rotate-3',
                            iconBg,
                        )}
                    >
                        <Icon className={cn('h-4.5 w-4.5', iconColor)} />
                    </div>
                )}
            </CardHeader>
            <CardContent className="relative px-5 pt-0 pb-4">
                <div className="text-3xl font-black tracking-tight">
                    {value}
                </div>
                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                    <p className="text-xs text-muted-foreground/80 dark:text-muted-foreground/50">
                        {hint}
                    </p>
                    {badge && (
                        <Badge
                            variant={badgeVariant}
                            className="border border-border/80 bg-muted/60 px-2 py-0.5 text-[10px] font-bold dark:border-white/5 dark:bg-white/5"
                        >
                            {badge}
                        </Badge>
                    )}
                </div>
            </CardContent>
        </Card>
    );

    if (href) {
        return <Link href={href}>{content}</Link>;
    }

    return content;
}

function OrgSnapshotTile({
    icon: Icon,
    label,
    value,
    sub,
    iconColor,
    iconBg,
    href,
}: {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    value: number;
    sub?: string;
    iconColor: string;
    iconBg: string;
    href: string;
}): ReactElement {
    return (
        <Link
            href={href}
            className="group flex items-center gap-3 rounded-2xl border border-border/80 bg-muted/10 px-4 py-3.5 transition-all duration-300 hover:-translate-y-0.5 hover:border-border hover:bg-muted/30 hover:shadow-lg dark:border-white/5 dark:bg-white/[0.02] dark:hover:border-white/10 dark:hover:bg-white/[0.04]"
        >
            <div
                className={cn(
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border transition-all duration-300 group-hover:scale-110 group-hover:rotate-3',
                    iconBg,
                )}
            >
                <Icon className={cn('h-4.5 w-4.5', iconColor)} />
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-xs font-semibold text-muted-foreground/80 transition-colors group-hover:text-muted-foreground/80 dark:text-muted-foreground/50">
                    {label}
                </p>
                <p className="mt-0.5 text-xl font-black tracking-tight tabular-nums">
                    {value.toLocaleString()}
                </p>
                {sub && (
                    <p className="mt-0.5 text-[10px] text-muted-foreground/85 dark:text-muted-foreground/55">
                        {sub}
                    </p>
                )}
            </div>
            <ArrowUpRight className="h-4 w-4 shrink-0 text-muted-foreground/30 opacity-0 transition-all duration-300 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100" />
        </Link>
    );
}
