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
    TrendingUp,
    Layers,
    BarChart3,
    CalendarDays,
    Sparkles,
    ChevronRight,
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
import { DistributionBarChart } from '@/features/dashboard/charts/distribution-bar-chart';
import { DocumentHealthChart } from '@/features/dashboard/charts/document-health-chart';
import { WorkforceTrendChart } from '@/features/dashboard/charts/workforce-trend-chart';
import type { DashboardProps } from '@/features/dashboard/dashboard-types';
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
}: DashboardProps): ReactElement {
    usePoll(60_000, { only: ['document_compliance', 'employee_analytics', 'recent_hires'] });

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
                        className="rounded-xl glass-card hover:bg-white/10"
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
                    gradient="from-blue-500/10 to-indigo-500/5"
                    iconColor="text-blue-400"
                    iconBg="bg-blue-500/10 border-blue-500/20"
                    accent="border-blue-500/20 hover:border-blue-500/30 hover:shadow-blue-500/5 hover:bg-blue-500/[0.01]"
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
                    gradient="from-violet-500/10 to-purple-500/5"
                    iconColor="text-violet-400"
                    iconBg="bg-violet-500/10 border-violet-500/20"
                    accent="border-violet-500/20 hover:border-violet-500/30 hover:shadow-violet-500/5 hover:bg-violet-500/[0.01]"
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
                    gradient={documentCompliance.expired > 0 ? 'from-red-500/15 to-rose-500/5' : 'from-white/5 to-transparent'}
                    iconColor={documentCompliance.expired > 0 ? 'text-red-400' : 'text-muted-foreground/60'}
                    iconBg={documentCompliance.expired > 0 ? 'bg-red-500/10 border-red-500/20' : 'bg-white/5 border-white/10'}
                    accent={documentCompliance.expired > 0 ? 'border-red-500/25 hover:border-red-500/40 hover:shadow-red-500/5 hover:bg-red-500/[0.01]' : 'border-white/5 hover:border-white/10'}
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
                    iconColor="text-indigo-400"
                    iconBg="bg-indigo-400/10 border-indigo-400/20"
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
                    icon={TrendingUp}
                    label="Expiring in 30 Days"
                    value={documentCompliance.expiring_30}
                    iconColor="text-yellow-400"
                    iconBg="bg-yellow-400/10 border-yellow-400/20"
                    href={documents.url({ query: { expiry: 'expiring_30' } })}
                />
            </div>

            {/* ── Workforce trend — full width ───────────────────────── */}
            <Card className="glass-card mb-6 overflow-hidden border-white/5 bg-white/[0.02]">
                <CardHeader className="border-b border-white/5 pb-4 bg-white/[0.01]">
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
                <Card className="glass-card overflow-hidden lg:col-span-2 border-white/5 bg-white/[0.02]">
                    <CardHeader className="border-b border-white/5 pb-4 bg-white/[0.01]">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Employees by Department
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs text-muted-foreground/60 font-medium">
                                    Distribution across your organization structure
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-500/10 border border-indigo-500/20">
                                <Layers className="h-4 w-4 text-indigo-400" />
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5">
                        <DistributionBarChart data={employeesByDepartment} />
                    </CardContent>
                </Card>

                <Card className="glass-card overflow-hidden border-white/5 bg-white/[0.02]">
                    <CardHeader className="border-b border-white/5 pb-4 bg-white/[0.01]">
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
                <Card className="glass-card overflow-hidden border-white/5 bg-white/[0.02]">
                    <CardHeader className="border-b border-white/5 pb-4 bg-white/[0.01]">
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

                <Card className="glass-card overflow-hidden border-white/5 bg-white/[0.02]">
                    <CardHeader className="border-b border-white/5 pb-4 bg-white/[0.01]">
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
                                    <Badge variant="secondary" className="tabular-nums font-bold border border-white/5 bg-white/5 px-2">
                                        {recentHires.length}
                                    </Badge>
                                )}
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-violet-500/10 border border-violet-500/20">
                                    <Sparkles className="h-4 w-4 text-violet-400" />
                                </div>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2 pt-4">
                        {recentHires.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
                                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/[0.02] border border-dashed border-white/10">
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
                                        className="group flex items-center gap-3 rounded-xl border border-white/5 bg-white/[0.01] p-3 transition-all duration-300 hover:border-white/10 hover:bg-white/[0.03] hover:-translate-y-0.5"
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

            {/* ── Detail sections ───────────────────────────────────── */}
            <div className="grid gap-6 lg:grid-cols-2">
                <Card className="glass-card overflow-hidden border-white/5 bg-white/[0.02]">
                    <CardHeader className="border-b border-white/5 pb-4 bg-white/[0.01]">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Employee Status
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs text-muted-foreground/60 font-medium">
                                    Workforce breakdown by current status
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1">
                                <div className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500 shadow-[0_0_6px_rgba(16,185,129,0.5)]" />
                                <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-400">
                                    Live
                                </span>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-3 pt-4">
                        <StatusBar
                            label="Active"
                            value={employeeAnalytics.active}
                            total={employeeAnalytics.total}
                            color="bg-emerald-500"
                            glowColor="shadow-emerald-500/20"
                            href={employees.url({ query: { status: 'active' } })}
                        />
                        <StatusBar
                            label="On Leave"
                            value={employeeAnalytics.on_leave}
                            total={employeeAnalytics.total}
                            color="bg-amber-500"
                            glowColor="shadow-amber-500/20"
                            href={employees.url({ query: { status: 'on_leave' } })}
                        />
                        <StatusBar
                            label="Inactive"
                            value={employeeAnalytics.inactive}
                            total={employeeAnalytics.total}
                            color="bg-blue-500"
                            glowColor="shadow-blue-500/20"
                            href={employees.url({ query: { status: 'inactive' } })}
                        />
                        <StatusBar
                            label="Terminated"
                            value={employeeAnalytics.terminated}
                            total={employeeAnalytics.total}
                            color="bg-red-500"
                            glowColor="shadow-red-500/20"
                            href={employees.url({ query: { status: 'terminated' } })}
                        />
                    </CardContent>
                </Card>

                <Card className="glass-card overflow-hidden border-white/5 bg-white/[0.02]">
                    <CardHeader className="border-b border-white/5 pb-4 bg-white/[0.01]">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight text-foreground/95">
                                    Document Compliance
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-xs text-muted-foreground/60 font-medium">
                                    {documentCompliance.total_documents} total documents tracked
                                </CardDescription>
                            </div>
                            <ComplianceRing rate={documentCompliance.compliance_rate} />
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2.5 pt-4">
                        <AtGlanceItem
                            title="Expired documents"
                            subtitle="Immediate action required"
                            href={documents.url({ query: { expiry: 'expired' } })}
                            value={String(documentCompliance.expired)}
                            urgent={documentCompliance.expired > 0}
                        />
                        <AtGlanceItem
                            title="Expiring within 7 days"
                            subtitle="Urgent renewals"
                            href={documents.url({ query: { expiry: 'expiring_7' } })}
                            value={String(documentCompliance.expiring_7)}
                            urgent={documentCompliance.expiring_7 > 0}
                        />
                        <AtGlanceItem
                            title="Expiring within 15 days"
                            subtitle="Plan ahead"
                            href={documents.url({ query: { expiry: 'expiring_15' } })}
                            value={String(documentCompliance.expiring_15)}
                        />
                        <AtGlanceItem
                            title="Expiring within 30 days"
                            subtitle="Expiry-tracked documents"
                            href={documents.url({ query: { expiry: 'expiring_30' } })}
                            value={String(documentCompliance.expiring_30)}
                        />
                        <AtGlanceItem
                            title="Uploaded this month"
                            subtitle={`${documentCompliance.total_documents} total on record`}
                            href={documents.url()}
                            value={String(documentCompliance.uploaded_this_month)}
                        />
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
            <div className="h-px flex-1 bg-white/5" />
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
                        <Badge variant={badgeVariant} className="text-[10px] font-bold px-2 py-0.5 border border-white/5 bg-white/5">
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
            className="group flex items-center gap-3 rounded-2xl border border-white/5 bg-white/[0.02] px-4 py-3.5 transition-all duration-300 hover:border-white/10 hover:bg-white/[0.04] hover:-translate-y-0.5 hover:shadow-lg"
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

function StatusBar({
    label,
    value,
    total,
    color,
    glowColor,
    href,
}: {
    label: string;
    value: number;
    total: number;
    color: string;
    glowColor?: string;
    href: string;
}): ReactElement {
    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;

    return (
        <Link
            href={href}
            className="group flex flex-col gap-2 rounded-xl border border-white/5 bg-white/[0.015] p-3 transition-all duration-300 hover:border-white/10 hover:bg-white/[0.035]"
        >
            <div className="flex items-center justify-between text-sm">
                <span className="font-semibold text-foreground/80 transition-colors group-hover:text-foreground">
                    {label}
                </span>
                <div className="flex items-center gap-2.5">
                    <span className="font-bold text-foreground/90 tabular-nums">{value.toLocaleString()}</span>
                    <span className="min-w-[2.5rem] rounded-full bg-white/5 border border-white/5 px-2 py-0.5 text-center text-[10px] font-bold text-muted-foreground/60">
                        {percentage}%
                    </span>
                </div>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-white/5 border border-white/5">
                <div
                    className={cn("h-full rounded-full shadow-xs transition-all duration-1000", color, glowColor)}
                    style={{ width: `${percentage}%` }}
                />
            </div>
        </Link>
    );
}

function AtGlanceItem({
    title,
    subtitle,
    value,
    href,
    urgent = false,
}: {
    title: string;
    subtitle: string;
    value: string;
    href: string;
    urgent?: boolean;
}): ReactElement {
    return (
        <Link
            href={href}
            className="group flex items-center justify-between gap-4 rounded-xl border border-white/5 bg-white/[0.015] p-3 transition-all duration-300 hover:border-white/10 hover:bg-white/[0.035]"
        >
            <div className="min-w-0">
                <div className="truncate text-sm font-semibold text-foreground/80 transition-colors group-hover:text-foreground">
                    {title}
                </div>
                <div className="truncate text-[10px] font-bold uppercase tracking-wider text-muted-foreground/35 mt-0.5 group-hover:text-muted-foreground/50 transition-colors">
                    {subtitle}
                </div>
            </div>
            <div className="flex items-center gap-2">
                <div
                    className={cn(
                        "min-w-[2rem] rounded-full border px-2.5 py-0.5 text-center text-xs font-bold tabular-nums transition-colors",
                        urgent && value !== '0'
                            ? "border-red-500/20 bg-red-500/10 text-red-400"
                            : "border-white/5 bg-white/5 text-muted-foreground group-hover:text-foreground"
                    )}
                >
                    {value}
                </div>
                {urgent && value !== '0' && (
                    <div className="h-1.5 w-1.5 animate-pulse rounded-full bg-red-500" />
                )}
                <ArrowUpRight className="h-3.5 w-3.5 text-muted-foreground/30 opacity-0 transition-all duration-300 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100" />
            </div>
        </Link>
    );
}

/**
 * A small SVG ring that visualises the compliance rate (0–100).
 * Green when ≥80 %, amber between 50–79 %, red below 50 %.
 */
function ComplianceRing({ rate }: { rate: number }): ReactElement {
    const radius = 20;
    const circumference = 2 * Math.PI * radius;
    const dashOffset = circumference - (rate / 100) * circumference;
    const color =
        rate >= 80 ? '#10b981' : rate >= 50 ? '#f59e0b' : '#ef4444';
    const bgColor =
        rate >= 80 ? 'rgba(16,185,129,0.08)' : rate >= 50 ? 'rgba(245,158,11,0.08)' : 'rgba(239,68,68,0.08)';
    const borderColor =
        rate >= 80 ? 'rgba(16,185,129,0.15)' : rate >= 50 ? 'rgba(245,158,11,0.15)' : 'rgba(239,68,68,0.15)';

    return (
        <div
            className="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-full border"
            style={{ background: bgColor, borderColor: borderColor }}
        >
            <svg width="56" height="56" viewBox="0 0 56 56" className="-rotate-90">
                <circle
                    cx="28"
                    cy="28"
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="3.5"
                    className="text-white/5"
                />
                <circle
                    cx="28"
                    cy="28"
                    r={radius}
                    fill="none"
                    stroke={color}
                    strokeWidth="3.5"
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={dashOffset}
                    style={{ transition: 'stroke-dashoffset 0.8s ease' }}
                />
            </svg>
            <span className="absolute text-[10px] font-black tabular-nums" style={{ color }}>
                {rate}%
            </span>
        </div>
    );
}
