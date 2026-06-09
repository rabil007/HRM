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
import type { ReactElement } from 'react';
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
import { AttendanceTrendChart } from '@/features/dashboard/charts/attendance-trend-chart';
import { DistributionBarChart } from '@/features/dashboard/charts/distribution-bar-chart';
import { DocumentHealthChart } from '@/features/dashboard/charts/document-health-chart';
import { WorkforceTrendChart } from '@/features/dashboard/charts/workforce-trend-chart';
import type { DashboardProps } from '@/features/dashboard/dashboard-types';
import { formatDisplayDateTime } from '@/lib/format-date';
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
        only: ['document_compliance', 'employee_analytics', 'recent_hires', 'attendance_analytics'],
    });

    const placeholder = (key: string) =>
        `${dashboard.url()}?module=${encodeURIComponent(key)}`;

    const employeeActiveRate =
        employeeAnalytics.total > 0
            ? Math.round((employeeAnalytics.active / employeeAnalytics.total) * 100)
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
                        <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/60">
                            Live · Real-time Intelligence
                        </span>
                    </div>
                    <h1 className="text-4xl font-extrabold tracking-tight bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-transparent">
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
                    <Button className="rounded-xl shadow-lg shadow-primary/25 hover:shadow-primary/30" asChild>
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
                    className="group mb-8 flex items-center gap-3 rounded-2xl border border-red-500/25 bg-red-500/5 px-5 py-4 transition-all duration-300 hover:border-red-500/40 hover:bg-red-500/10 hover:-translate-y-0.5 hover:shadow-[0_8px_30px_rgba(239,68,68,0.06)]"
                >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-red-500/10 border border-red-500/20 group-hover:scale-105 transition-transform duration-300">
                        <AlertTriangle className="h-4.5 w-4.5 text-red-400" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-bold text-red-400">
                            Immediate attention required
                        </p>
                        <p className="text-xs text-muted-foreground/75 mt-0.5">
                            {documentCompliance.expired > 0 &&
                                `${documentCompliance.expired} expired document${documentCompliance.expired !== 1 ? 's' : ''}`}
                            {documentCompliance.expired > 0 && documentCompliance.expiring_7 > 0 && ' · '}
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
                    gradient={documentCompliance.expired > 0 ? 'from-red-500/15 to-rose-500/5' : 'from-muted/40 dark:from-white/5 to-transparent'}
                    icon={FileText}
                    iconBg={documentCompliance.expired > 0 ? 'bg-red-500/10 border-red-500/20' : 'bg-muted/60 border-border/80 dark:bg-white/5 dark:border-white/10'}
                    accent={documentCompliance.expired > 0 ? 'border-red-500/25 hover:border-red-500/40 hover:shadow-red-500/5 hover:bg-red-500/[0.01]' : 'border-border/60 hover:border-border dark:border-white/5 dark:hover:border-white/10'}
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
                    href="/hikvision/access-events"
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
                    href="/hikvision/access-events?attendance_status=checkIn"
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
                    href="/hikvision/access-events?attendance_status=checkOut"
                />
                <MetricCard
                    title="Linked Employees"
                    value={attendanceAnalytics.linked_employees.toLocaleString()}
                    hint="Employees linked to Hikvision"
                    icon={Radio}
                    gradient="from-cyan-500/10 to-teal-500/5"
                    iconColor="text-cyan-400"
                    iconBg="bg-cyan-500/10 border-cyan-500/20"
                    accent="border-cyan-500/20 hover:border-cyan-500/30 hover:shadow-cyan-500/5 hover:bg-cyan-500/[0.01]"
                    badge={`${attendanceAnalytics.events_today} events today`}
                    badgeVariant="info"
                    href="/hikvision/persons"
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
                    icon={LogIn}
                    label="Today's Events"
                    value={attendanceAnalytics.events_today}
                    sub={`${attendanceAnalytics.check_ins_today} check-ins`}
                    iconColor="text-cyan-400"
                    iconBg="bg-cyan-400/10 border-cyan-400/20"
                    href="/hikvision/access-events"
                />
            </div>

            {/* ── Workforce trend — full width ───────────────────────── */}
            <Card className="glass-card mb-6 overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
                <CardHeader className="border-b border-border/60 dark:border-white/5 pb-4 bg-muted/5 dark:bg-white/[0.01]">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                Workforce Trends
                            </CardTitle>
                            <CardDescription className="mt-0.5 text-xs text-muted-foreground/60 font-medium">
                                Headcount, hiring &amp; documents over the last 6 months
                            </CardDescription>
                        </div>
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 border border-primary/20">
                            <BarChart3 className="h-4 w-4 text-primary" />
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="pt-5">
                    <WorkforceTrendChart data={workforceTrends} />
                </CardContent>
            </Card>

            {/* ── Charts row ────────────────────────────────────────── */}
            <div className="mb-6 grid gap-6 lg:grid-cols-3">
                <Card className="glass-card overflow-hidden lg:col-span-2 dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 dark:border-white/5 pb-4 bg-muted/5 dark:bg-white/[0.01]">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Employees by Department
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs text-muted-foreground/60 font-medium">
                                    Distribution across your organization structure
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 border border-primary/20">
                                <Layers className="h-4 w-4 text-primary" />
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5">
                        <DistributionBarChart data={employeesByDepartment} />
                    </CardContent>
                </Card>

                <Card className="glass-card overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 dark:border-white/5 pb-4 bg-muted/5 dark:bg-white/[0.01]">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Document Health
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs text-muted-foreground/60 font-medium">
                                    Expiry status breakdown
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 border border-emerald-500/20">
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
                <Card className="glass-card overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 dark:border-white/5 pb-4 bg-muted/5 dark:bg-white/[0.01]">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Employees by Branch
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs text-muted-foreground/60 font-medium">
                                    Headcount per branch location
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-teal-500/10 border border-teal-500/20">
                                <Building2 className="h-4 w-4 text-teal-400" />
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5">
                        <DistributionBarChart
                            data={employeesByBranch}
                            layout="horizontal"
                        />
                    </CardContent>
                </Card>

                <Card className="glass-card overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 dark:border-white/5 pb-4 bg-muted/5 dark:bg-white/[0.01]">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Recent Hires
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs text-muted-foreground/60 font-medium">
                                    Latest employees added to the system
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                {recentHires.length > 0 && (
                                    <Badge variant="secondary" className="tabular-nums font-bold border border-border/80 bg-muted/60 dark:border-white/5 dark:bg-white/5 px-2">
                                        {recentHires.length}
                                    </Badge>
                                )}
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-accent/10 border border-accent/20">
                                    <Sparkles className="h-4 w-4 text-accent" />
                                </div>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2 pt-4">
                        {recentHires.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
                                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted/20 border border-dashed border-border dark:bg-white/[0.02] dark:border-white/10">
                                    <Users className="h-5 w-5 text-muted-foreground/30" />
                                </div>
                                <p className="text-sm font-medium text-muted-foreground/50">
                                    No employees on record yet
                                </p>
                            </div>
                        ) : (
                            recentHires.map((hire) => {
                                const hue = nameToHue(hire.name);

                                return (
                                    <Link
                                        key={hire.id}
                                        href={showEmployee.url({ employee: hire.id })}
                                        className="group flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all duration-300 hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/[0.01] dark:hover:border-white/10 dark:hover:bg-white/[0.03] hover:-translate-y-0.5"
                                    >
                                        <Avatar className="size-9 shrink-0 ring-2 ring-background shadow-md">
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
                                            <p className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground/45 mt-0.5">
                                                {hire.employee_no} · {hire.hired_at}
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
                <Card className="glass-card overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 dark:border-white/5 pb-4 bg-muted/5 dark:bg-white/[0.01]">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Attendance Trends
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs text-muted-foreground/60 font-medium">
                                    Check-ins and check-outs over the last 7 days
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-cyan-500/10 border border-cyan-500/20">
                                <Radio className="h-4 w-4 text-cyan-400" />
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5">
                        <AttendanceTrendChart data={attendanceAnalytics.weekly_trends} />
                    </CardContent>
                </Card>

                <Card className="glass-card overflow-hidden dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 dark:border-white/5 pb-4 bg-muted/5 dark:bg-white/[0.01]">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Recent Attendance
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs text-muted-foreground/60 font-medium">
                                    Latest access events from linked employees
                                </CardDescription>
                            </div>
                            <Button variant="outline" size="sm" className="h-8 rounded-lg text-xs" asChild>
                                <Link href="/hikvision/access-events">View all</Link>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2 pt-4">
                        {attendanceAnalytics.recent_events.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
                                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted/20 border border-dashed border-border dark:bg-white/[0.02] dark:border-white/10">
                                    <Radio className="h-5 w-5 text-muted-foreground/30" />
                                </div>
                                <p className="text-sm font-medium text-muted-foreground/50">
                                    No attendance events yet
                                </p>
                                <p className="text-xs text-muted-foreground/40">
                                    Link employees to Hikvision persons to see access events here
                                </p>
                            </div>
                        ) : (
                            attendanceAnalytics.recent_events.map((event) => {
                                const displayName =
                                    event.employee_name ?? event.person_name ?? 'Unknown';
                                const isCheckIn = event.attendance_status === 'checkIn';
                                const isCheckOut = event.attendance_status === 'checkOut';

                                const content = (
                                    <div className="group flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all duration-300 hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/[0.01] dark:hover:border-white/10 dark:hover:bg-white/[0.03]">
                                        <div
                                            className={cn(
                                                'flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border',
                                                isCheckIn
                                                    ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400'
                                                    : isCheckOut
                                                      ? 'bg-sky-500/10 border-sky-500/20 text-sky-400'
                                                      : 'bg-muted/60 border-border/80 text-muted-foreground dark:bg-white/5 dark:border-white/10',
                                            )}
                                        >
                                            {isCheckOut ? (
                                                <LogOut className="h-4 w-4" />
                                            ) : (
                                                <LogIn className="h-4 w-4" />
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold text-foreground/80">
                                                {displayName}
                                            </p>
                                            <p className="mt-0.5 truncate text-[10px] font-bold uppercase tracking-wider text-muted-foreground/45">
                                                {formatDisplayDateTime(event.occurrence_time)}
                                                {event.device_name ? ` · ${event.device_name}` : ''}
                                            </p>
                                        </div>
                                        <Badge
                                            variant="secondary"
                                            className="shrink-0 border border-border/80 bg-muted/60 dark:border-white/5 dark:bg-white/5 text-[10px] font-bold uppercase"
                                        >
                                            {isCheckIn ? 'In' : isCheckOut ? 'Out' : '—'}
                                        </Badge>
                                    </div>
                                );

                                if (event.employee_id) {
                                    return (
                                        <Link
                                            key={event.id}
                                            href={showEmployee.url({ employee: event.employee_id })}
                                            className="block"
                                        >
                                            {content}
                                        </Link>
                                    );
                                }

                                return (
                                    <div key={event.id}>{content}</div>
                                );
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
    badgeVariant?: 'default' | 'secondary' | 'info' | 'success' | 'warning' | 'destructive' | 'outline';
    href?: string;
}): ReactElement {
    const content = (
        <Card
            className={cn(
                "group relative overflow-hidden transition-all duration-300 hover:-translate-y-1 hover:shadow-xl glass-card p-0 gap-0",
                accent,
                href && "cursor-pointer"
            )}
        >
            {/* Gradient accent */}
            <div className={cn("absolute inset-x-0 top-0 h-20 bg-gradient-to-b opacity-15 group-hover:opacity-25 transition-opacity duration-300", gradient)} />
            <CardHeader className="relative flex flex-row items-center justify-between space-y-0 pb-1 pt-4 px-5">
                <CardTitle className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground/60 transition-colors group-hover:text-foreground/80">
                    {title}
                </CardTitle>
                {Icon && (
                    <div className={cn("flex h-9 w-9 items-center justify-center rounded-xl border transition-all duration-300 group-hover:scale-110 group-hover:rotate-3", iconBg)}>
                        <Icon className={cn("h-4.5 w-4.5", iconColor)} />
                    </div>
                )}
            </CardHeader>
            <CardContent className="relative pb-4 pt-0 px-5">
                <div className="text-3xl font-black tracking-tight">{value}</div>
                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                    <p className="text-xs text-muted-foreground/50">{hint}</p>
                    {badge && (
                        <Badge variant={badgeVariant} className="text-[10px] font-bold px-2 py-0.5 border border-border/80 bg-muted/60 dark:border-white/5 dark:bg-white/5">
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
            className="group flex items-center gap-3 rounded-2xl border border-border/80 bg-muted/10 px-4 py-3.5 transition-all duration-300 hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/[0.02] dark:hover:border-white/10 dark:hover:bg-white/[0.04] hover:-translate-y-0.5 hover:shadow-lg"
        >
            <div className={cn("flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border transition-all duration-300 group-hover:scale-110 group-hover:rotate-3", iconBg)}>
                <Icon className={cn("h-4.5 w-4.5", iconColor)} />
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-xs font-semibold text-muted-foreground/50 transition-colors group-hover:text-muted-foreground/80">{label}</p>
                <p className="text-xl font-black tracking-tight tabular-nums mt-0.5">
                    {value.toLocaleString()}
                </p>
                {sub && (
                    <p className="text-[10px] text-muted-foreground/35 mt-0.5">{sub}</p>
                )}
            </div>
            <ArrowUpRight className="h-4 w-4 shrink-0 text-muted-foreground/30 opacity-0 transition-all duration-300 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100" />
        </Link>
    );
}

