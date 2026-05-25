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
import { Link, usePoll } from '@inertiajs/react';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { DistributionBarChart } from '@/features/dashboard/charts/distribution-bar-chart';
import { DocumentHealthChart } from '@/features/dashboard/charts/document-health-chart';
import { WorkforceTrendChart } from '@/features/dashboard/charts/workforce-trend-chart';
import type { DashboardProps } from '@/features/dashboard/dashboard-types';
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
                        <span className="flex h-2 w-2 animate-pulse rounded-full bg-emerald-500" />
                        <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/70">
                            Live · Real-time Intelligence
                        </span>
                    </div>
                    <h1 className="text-4xl font-extrabold tracking-tight">
                        HR Dashboard
                    </h1>
                    <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <CalendarDays className="h-3.5 w-3.5" />
                        {today}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <Button
                        variant="outline"
                        className="rounded-xl"
                        asChild
                    >
                        <Link href={employees.url()}>
                            <LayoutGrid className="mr-2 h-4 w-4" />
                            Directory
                        </Link>
                    </Button>
                    <Button className="rounded-xl shadow-lg" asChild>
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
                    className="group mb-6 flex items-center gap-3 rounded-2xl border border-destructive/20 bg-destructive/5 px-5 py-4 transition-all hover:border-destructive/40 hover:bg-destructive/10"
                >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-destructive/10">
                        <AlertTriangle className="h-4.5 w-4.5 text-destructive" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-semibold text-destructive">
                            Immediate attention required
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {documentCompliance.expired > 0 &&
                                `${documentCompliance.expired} expired document${documentCompliance.expired !== 1 ? 's' : ''}`}
                            {documentCompliance.expired > 0 && documentCompliance.expiring_7 > 0 && ' · '}
                            {documentCompliance.expiring_7 > 0 &&
                                `${documentCompliance.expiring_7} expiring within 7 days`}
                        </p>
                    </div>
                    <ChevronRight className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
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
                    gradient="from-blue-500/20 to-indigo-500/10"
                    iconColor="text-blue-500"
                    iconBg="bg-blue-500/10"
                    accent="border-blue-500/20"
                    badge={`${employeeAnalytics.active} active`}
                    badgeVariant="info"
                    href={employees.url()}
                />
                <MetricCard
                    title="Active"
                    value={employeeAnalytics.active.toLocaleString()}
                    hint="Currently employed"
                    icon={UserCheck}
                    gradient="from-emerald-500/20 to-green-500/10"
                    iconColor="text-emerald-500"
                    iconBg="bg-emerald-500/10"
                    accent="border-emerald-500/20"
                    badge={`${employeeActiveRate}% of workforce`}
                    badgeVariant="success"
                    href={employees.url({ query: { status: 'active' } })}
                />
                <MetricCard
                    title="New Hires"
                    value={employeeAnalytics.new_hires_this_month.toLocaleString()}
                    hint="Joined this month"
                    icon={UserPlus}
                    gradient="from-violet-500/20 to-purple-500/10"
                    iconColor="text-violet-400"
                    iconBg="bg-violet-400/10"
                    accent="border-violet-400/20"
                    href={employees.url()}
                />
                <MetricCard
                    title="On Leave / Inactive"
                    value={(
                        employeeAnalytics.on_leave + employeeAnalytics.inactive
                    ).toLocaleString()}
                    hint="On leave or inactive"
                    icon={UserX}
                    gradient="from-amber-500/20 to-orange-500/10"
                    iconColor="text-amber-400"
                    iconBg="bg-amber-400/10"
                    accent="border-amber-400/20"
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
                    gradient="from-sky-500/20 to-cyan-500/10"
                    iconColor="text-sky-500"
                    iconBg="bg-sky-500/10"
                    accent="border-sky-500/20"
                    badge={`+${documentCompliance.uploaded_this_month} this month`}
                    badgeVariant="info"
                    href={documents.url()}
                />
                <MetricCard
                    title="Compliance Rate"
                    value={`${documentCompliance.compliance_rate}%`}
                    hint="Non-expired document share"
                    icon={ShieldCheck}
                    gradient="from-emerald-500/20 to-teal-500/10"
                    iconColor="text-emerald-500"
                    iconBg="bg-emerald-500/10"
                    accent="border-emerald-500/20"
                    badge={`${documentCompliance.avg_per_employee} avg / employee`}
                    badgeVariant="success"
                    href={documents.url()}
                />
                <MetricCard
                    title="Expired"
                    value={documentCompliance.expired.toLocaleString()}
                    hint="Require immediate action"
                    icon={AlertTriangle}
                    gradient={documentCompliance.expired > 0 ? 'from-red-500/20 to-rose-500/10' : 'from-muted/20 to-muted/5'}
                    iconColor={documentCompliance.expired > 0 ? 'text-destructive' : 'text-muted-foreground'}
                    iconBg={documentCompliance.expired > 0 ? 'bg-destructive/10' : 'bg-muted/40'}
                    accent={documentCompliance.expired > 0 ? 'border-destructive/20' : 'border-border'}
                    badgeVariant="destructive"
                    href={documents.url({ query: { expiry: 'expired' } })}
                />
                <MetricCard
                    title="Expiring in 7 Days"
                    value={documentCompliance.expiring_7.toLocaleString()}
                    hint="Urgent renewals needed"
                    icon={Clock}
                    gradient="from-orange-500/20 to-amber-500/10"
                    iconColor="text-orange-400"
                    iconBg="bg-orange-400/10"
                    accent="border-orange-400/20"
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
                    iconBg="bg-sky-400/10"
                    href={employees.url()}
                />
                <OrgSnapshotTile
                    icon={Layers}
                    label="Departments"
                    value={organizationSnapshot.departments}
                    iconColor="text-indigo-400"
                    iconBg="bg-indigo-400/10"
                    href={employees.url()}
                />
                <OrgSnapshotTile
                    icon={Building2}
                    label="Branches"
                    value={organizationSnapshot.branches}
                    iconColor="text-teal-400"
                    iconBg="bg-teal-400/10"
                    href={employees.url()}
                />
                <OrgSnapshotTile
                    icon={TrendingUp}
                    label="Expiring in 30 Days"
                    value={documentCompliance.expiring_30}
                    iconColor="text-yellow-400"
                    iconBg="bg-yellow-400/10"
                    href={documents.url({ query: { expiry: 'expiring_30' } })}
                />
            </div>

            {/* ── Workforce trend — full width ───────────────────────── */}
            <Card className="glass-card mb-6 overflow-hidden">
                <CardHeader className="border-b border-border/50 pb-4">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <CardTitle className="text-lg font-bold tracking-tight">
                                Workforce Trends
                            </CardTitle>
                            <CardDescription className="mt-0.5 text-sm">
                                Headcount, hiring &amp; documents over the last 6 months
                            </CardDescription>
                        </div>
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10">
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
                <Card className="glass-card overflow-hidden lg:col-span-2">
                    <CardHeader className="border-b border-border/50 pb-4">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-lg font-bold tracking-tight">
                                    Employees by Department
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-sm">
                                    Distribution across your organization structure
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-500/10">
                                <Layers className="h-4 w-4 text-indigo-400" />
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5">
                        <DistributionBarChart data={employeesByDepartment} />
                    </CardContent>
                </Card>

                <Card className="glass-card overflow-hidden">
                    <CardHeader className="border-b border-border/50 pb-4">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-lg font-bold tracking-tight">
                                    Document Health
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-sm">
                                    Expiry status breakdown
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10">
                                <ShieldCheck className="h-4 w-4 text-emerald-500" />
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
                <Card className="glass-card overflow-hidden">
                    <CardHeader className="border-b border-border/50 pb-4">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-lg font-bold tracking-tight">
                                    Employees by Branch
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-sm">
                                    Headcount per branch location
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-teal-500/10">
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

                <Card className="glass-card overflow-hidden">
                    <CardHeader className="border-b border-border/50 pb-4">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <CardTitle className="text-lg font-bold tracking-tight">
                                    Recent Hires
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-sm">
                                    Latest employees added to the system
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                {recentHires.length > 0 && (
                                    <Badge variant="secondary" className="tabular-nums">
                                        {recentHires.length}
                                    </Badge>
                                )}
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-violet-500/10">
                                    <Sparkles className="h-4 w-4 text-violet-400" />
                                </div>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-2 pt-4">
                        {recentHires.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
                                <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted/50">
                                    <Users className="h-5 w-5 text-muted-foreground/50" />
                                </div>
                                <p className="text-sm font-medium text-muted-foreground">
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
                                        className="group flex items-center gap-3 rounded-xl border border-transparent bg-muted/30 p-3 transition-all hover:border-border hover:bg-muted/50"
                                    >
                                        <Avatar className="size-9 shrink-0 ring-2 ring-background">
                                            <AvatarFallback
                                                className="text-xs font-bold text-white"
                                                style={{
                                                    background: `hsl(${hue} 65% 45%)`,
                                                }}
                                            >
                                                {getInitials(hire.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-semibold transition-colors group-hover:text-primary">
                                                {hire.name}
                                            </p>
                                            <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                                {hire.employee_no} · {hire.hired_at}
                                            </p>
                                        </div>
                                        <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground opacity-0 transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100" />
                                    </Link>
                                );
                            })
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* ── Detail sections ───────────────────────────────────── */}
            <div className="grid gap-6 lg:grid-cols-2">
                <Card className="glass-card overflow-hidden">
                    <CardHeader className="border-b border-border/50 pb-4">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <CardTitle className="text-lg font-bold tracking-tight">
                                    Employee Status
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-sm">
                                    Workforce breakdown by current status
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-1.5 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1">
                                <div className="h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" />
                                <span className="text-[10px] font-bold uppercase tracking-wider text-emerald-500">
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

                <Card className="glass-card overflow-hidden">
                    <CardHeader className="border-b border-border/50 pb-4">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <CardTitle className="text-lg font-bold tracking-tight">
                                    Document Compliance
                                </CardTitle>
                                <CardDescription className="mt-0.5 text-sm">
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
        <div className="mb-3 flex items-center gap-2">
            <Icon className="h-3.5 w-3.5 text-muted-foreground/70" />
            <span className="text-[11px] font-bold uppercase tracking-[0.15em] text-muted-foreground/70">
                {label}
            </span>
            <div className="h-px flex-1 bg-border/60" />
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
            className={`group relative overflow-hidden border transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md ${accent} ${href ? 'cursor-pointer' : ''}`}
        >
            {/* Gradient accent */}
            <div className={`absolute inset-x-0 top-0 h-24 bg-gradient-to-b ${gradient} opacity-60`} />
            <CardHeader className="relative flex flex-row items-center justify-between space-y-0 pb-2 pt-5">
                <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground transition-colors group-hover:text-foreground/80">
                    {title}
                </CardTitle>
                {Icon && (
                    <div className={`flex h-9 w-9 items-center justify-center rounded-xl ${iconBg} transition-transform duration-200 group-hover:scale-110`}>
                        <Icon className={`h-4.5 w-4.5 ${iconColor}`} />
                    </div>
                )}
            </CardHeader>
            <CardContent className="relative pb-5">
                <div className="text-3xl font-extrabold tracking-tight">{value}</div>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    <p className="text-xs text-muted-foreground">{hint}</p>
                    {badge && (
                        <Badge variant={badgeVariant} className="text-[10px]">
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
            className="group flex items-center gap-3 rounded-2xl border border-border/60 bg-card/50 px-4 py-3.5 transition-all duration-200 hover:border-border hover:bg-card hover:shadow-sm"
        >
            <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${iconBg} transition-transform duration-200 group-hover:scale-110`}>
                <Icon className={`h-4.5 w-4.5 ${iconColor}`} />
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-xs font-semibold text-muted-foreground">{label}</p>
                <p className="text-xl font-extrabold tracking-tight tabular-nums">
                    {value.toLocaleString()}
                </p>
                {sub && (
                    <p className="text-[10px] text-muted-foreground/70">{sub}</p>
                )}
            </div>
            <ArrowUpRight className="h-4 w-4 shrink-0 text-muted-foreground opacity-0 transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-60" />
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
            className="group flex flex-col gap-2 rounded-xl border border-transparent bg-muted/30 p-3 transition-all duration-200 hover:border-border hover:bg-muted/50"
        >
            <div className="flex items-center justify-between text-sm">
                <span className="font-semibold transition-colors group-hover:text-primary">
                    {label}
                </span>
                <div className="flex items-center gap-2.5">
                    <span className="font-bold tabular-nums">{value.toLocaleString()}</span>
                    <span className="min-w-[2.5rem] rounded-full bg-muted px-2 py-0.5 text-center text-[10px] font-semibold text-muted-foreground">
                        {percentage}%
                    </span>
                </div>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-border/60">
                <div
                    className={`h-full rounded-full shadow-sm transition-all duration-700 ${color} ${glowColor ?? ''}`}
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
            className="group flex items-center justify-between gap-4 rounded-xl border border-transparent bg-muted/30 p-3 transition-all duration-200 hover:border-border hover:bg-muted/50"
        >
            <div className="min-w-0">
                <div className="truncate text-sm font-semibold transition-colors group-hover:text-primary">
                    {title}
                </div>
                <div className="truncate text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                    {subtitle}
                </div>
            </div>
            <div className="flex items-center gap-2">
                <div
                    className={`min-w-[2rem] rounded-full px-2 py-0.5 text-center text-sm font-bold tabular-nums ${
                        urgent && value !== '0'
                            ? 'bg-destructive/10 text-destructive'
                            : 'bg-muted text-foreground'
                    }`}
                >
                    {value}
                </div>
                {urgent && value !== '0' && (
                    <div className="h-1.5 w-1.5 animate-pulse rounded-full bg-destructive" />
                )}
                <ArrowUpRight className="h-3 w-3 text-muted-foreground opacity-0 transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100" />
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
        rate >= 80 ? '#34d399' : rate >= 50 ? '#fbbf24' : '#f87171';
    const bgColor =
        rate >= 80 ? 'rgba(52,211,153,0.12)' : rate >= 50 ? 'rgba(251,191,36,0.12)' : 'rgba(248,113,113,0.12)';

    return (
        <div
            className="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-full"
            style={{ background: bgColor }}
        >
            <svg width="56" height="56" viewBox="0 0 56 56" className="-rotate-90">
                <circle
                    cx="28"
                    cy="28"
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="4"
                    className="text-border/40"
                />
                <circle
                    cx="28"
                    cy="28"
                    r={radius}
                    fill="none"
                    stroke={color}
                    strokeWidth="4"
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={dashOffset}
                    style={{ transition: 'stroke-dashoffset 0.8s ease' }}
                />
            </svg>
            <span className="absolute text-[10px] font-bold tabular-nums" style={{ color }}>
                {rate}%
            </span>
        </div>
    );
}
