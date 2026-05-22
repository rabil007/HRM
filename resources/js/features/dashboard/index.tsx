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
} from 'lucide-react';
import type { ReactElement } from 'react';
import { Main } from '@/components/layout/main';
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
import { dashboard } from '@/routes';
import { documents, employees } from '@/routes/organization';
import { show as showEmployee } from '@/routes/organization/employees';

export type { DashboardProps } from '@/features/dashboard/dashboard-types';

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
    const placeholder = (key: string) =>
        `${dashboard.url()}?module=${encodeURIComponent(key)}`;

    const employeeComplianceRate =
        employeeAnalytics.total > 0
            ? Math.round((employeeAnalytics.active / employeeAnalytics.total) * 100)
            : 0;

    return (
        <Main>
            {/* Header */}
            <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div className="space-y-1.5">
                    <div className="mb-1 flex items-center gap-2">
                        <span className="flex h-2 w-2 animate-pulse rounded-full bg-primary" />
                        <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                            Real-time Intelligence
                        </span>
                    </div>
                    <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">
                        HR Dashboard
                    </h1>
                    <p className="text-sm font-medium text-muted-foreground/80">
                        Synthesized overview of your organizational health and compliance.
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <Button
                        variant="outline"
                        className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10"
                        asChild
                    >
                        <a href={employees.url()}>
                            <LayoutGrid className="mr-2 h-4 w-4" />
                            Directory
                        </a>
                    </Button>
                    <Button className="rounded-xl shadow-lg shadow-primary/20" asChild>
                        <a href={placeholder('quick-actions.create-employee')}>
                            <Plus className="mr-2 h-4 w-4" />
                            Create Employee
                        </a>
                    </Button>
                </div>
            </div>

            {/* Workforce KPIs */}
            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Metric
                    title="Total Employees"
                    value={employeeAnalytics.total.toLocaleString()}
                    hint="All employees on record"
                    icon={Users}
                    trend={`${employeeAnalytics.active} active`}
                    glow="glow-primary"
                    href={employees.url()}
                />
                <Metric
                    title="Active"
                    value={employeeAnalytics.active.toLocaleString()}
                    hint="Currently employed"
                    icon={UserCheck}
                    trend={`${employeeComplianceRate}% of workforce`}
                    glow="glow-success"
                    href={employees.url({ query: { status: 'active' } })}
                />
                <Metric
                    title="New Hires"
                    value={employeeAnalytics.new_hires_this_month.toLocaleString()}
                    hint="Joined this month"
                    icon={UserPlus}
                    glow="glow-accent"
                    href={employees.url()}
                />
                <Metric
                    title="On Leave / Inactive"
                    value={(
                        employeeAnalytics.on_leave + employeeAnalytics.inactive
                    ).toLocaleString()}
                    hint="On leave or inactive"
                    icon={UserX}
                    trend={
                        employeeAnalytics.terminated > 0
                            ? `${employeeAnalytics.terminated} terminated`
                            : undefined
                    }
                    glow="glow-info"
                    href={employees.url()}
                />
            </div>

            {/* Document & org KPIs */}
            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Metric
                    title="Total Documents"
                    value={documentCompliance.total_documents.toLocaleString()}
                    hint="All employee documents"
                    icon={FileText}
                    trend={`${documentCompliance.uploaded_this_month} this month`}
                    glow="glow-primary"
                    href={documents.url()}
                />
                <Metric
                    title="Compliance Rate"
                    value={`${documentCompliance.compliance_rate}%`}
                    hint="Non-expired document share"
                    icon={ShieldCheck}
                    trend={`${documentCompliance.avg_per_employee} avg / employee`}
                    glow="glow-success"
                    href={documents.url()}
                />
                <Metric
                    title="Expired"
                    value={documentCompliance.expired.toLocaleString()}
                    hint="Require immediate action"
                    icon={AlertTriangle}
                    glow={documentCompliance.expired > 0 ? 'glow-info' : undefined}
                    href={documents.url({ query: { expiry: 'expired' } })}
                />
                <Metric
                    title="Expiring in 7 Days"
                    value={documentCompliance.expiring_7.toLocaleString()}
                    hint="Urgent renewals needed"
                    icon={Clock}
                    glow="glow-accent"
                    href={documents.url({ query: { expiry: 'expiring_7' } })}
                />
            </div>

            {/* Secondary insights */}
            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Metric
                    title="User Accounts"
                    value={employeeAnalytics.with_user_account.toLocaleString()}
                    hint="Employees with login access"
                    icon={Link2}
                    trend={`${employeeAnalytics.without_user_account} without account`}
                    href={employees.url()}
                />
                <Metric
                    title="Departments"
                    value={organizationSnapshot.departments.toLocaleString()}
                    hint="Active org structure"
                    icon={Layers}
                    href={employees.url()}
                />
                <Metric
                    title="Branches"
                    value={organizationSnapshot.branches.toLocaleString()}
                    hint="Office locations"
                    icon={Building2}
                    href={employees.url()}
                />
                <Metric
                    title="Expiring in 30 Days"
                    value={documentCompliance.expiring_30.toLocaleString()}
                    hint="Plan renewals ahead"
                    icon={TrendingUp}
                    href={documents.url({ query: { expiry: 'expiring_30' } })}
                />
            </div>

            {/* Workforce trend — full width */}
            <Card className="glass-card mb-6">
                <CardHeader>
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <CardTitle className="text-xl font-bold tracking-tight">
                                Workforce Trends
                            </CardTitle>
                            <CardDescription className="text-sm font-medium">
                                Headcount, hiring, and document activity over the last 6 months.
                            </CardDescription>
                        </div>
                        <BarChart3 className="h-5 w-5 shrink-0 text-muted-foreground" />
                    </div>
                </CardHeader>
                <CardContent>
                    <WorkforceTrendChart data={workforceTrends} />
                </CardContent>
            </Card>

            {/* Charts row */}
            <div className="mb-6 grid gap-6 lg:grid-cols-3">
                <Card className="glass-card lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="text-xl font-bold tracking-tight">
                            Employees by Department
                        </CardTitle>
                        <CardDescription className="text-sm font-medium">
                            Distribution across your organization structure.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DistributionBarChart data={employeesByDepartment} />
                    </CardContent>
                </Card>

                <Card className="glass-card">
                    <CardHeader>
                        <CardTitle className="text-xl font-bold tracking-tight">
                            Document Health
                        </CardTitle>
                        <CardDescription className="text-sm font-medium">
                            Expiry status breakdown.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DocumentHealthChart data={documentHealth} />
                    </CardContent>
                </Card>
            </div>

            {/* Branch + recent hires */}
            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <Card className="glass-card">
                    <CardHeader>
                        <CardTitle className="text-xl font-bold tracking-tight">
                            Employees by Branch
                        </CardTitle>
                        <CardDescription className="text-sm font-medium">
                            Headcount per branch location.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DistributionBarChart
                            data={employeesByBranch}
                            layout="horizontal"
                        />
                    </CardContent>
                </Card>

                <Card className="glass-card">
                    <CardHeader>
                        <CardTitle className="text-xl font-bold tracking-tight">
                            Recent Hires
                        </CardTitle>
                        <CardDescription className="text-sm font-medium">
                            Latest employees added to the system.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {recentHires.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No employees on record yet.
                            </p>
                        ) : (
                            recentHires.map((hire) => (
                                <a
                                    key={hire.id}
                                    href={showEmployee.url({ employee: hire.id })}
                                    className="group flex items-center justify-between gap-3 rounded-xl border border-white/5 bg-white/5 p-3 transition-all hover:bg-white/10"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold group-hover:text-primary">
                                            {hire.name}
                                        </p>
                                        <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                            {hire.employee_no} · {hire.hired_at}
                                        </p>
                                    </div>
                                    <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground opacity-0 transition-all group-hover:opacity-100" />
                                </a>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Detail sections */}
            <div className="grid gap-6 lg:grid-cols-2">
                <Card className="glass-card">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle className="text-xl font-bold tracking-tight">
                                Employee Status
                            </CardTitle>
                            <CardDescription className="text-sm font-medium">
                                Workforce breakdown by current status.
                            </CardDescription>
                        </div>
                        <div className="flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary/10 px-3 py-1">
                            <div className="h-2 w-2 rounded-full bg-primary" />
                            <span className="text-[10px] font-bold uppercase tracking-wider text-primary">
                                Live
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        <StatusBar
                            label="Active"
                            value={employeeAnalytics.active}
                            total={employeeAnalytics.total}
                            color="bg-emerald-500"
                            href={employees.url({ query: { status: 'active' } })}
                        />
                        <StatusBar
                            label="On Leave"
                            value={employeeAnalytics.on_leave}
                            total={employeeAnalytics.total}
                            color="bg-amber-500"
                            href={employees.url({ query: { status: 'on_leave' } })}
                        />
                        <StatusBar
                            label="Inactive"
                            value={employeeAnalytics.inactive}
                            total={employeeAnalytics.total}
                            color="bg-blue-500"
                            href={employees.url({ query: { status: 'inactive' } })}
                        />
                        <StatusBar
                            label="Terminated"
                            value={employeeAnalytics.terminated}
                            total={employeeAnalytics.total}
                            color="bg-red-500"
                            href={employees.url({ query: { status: 'terminated' } })}
                        />
                    </CardContent>
                </Card>

                <Card className="glass-card">
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle className="text-xl font-bold tracking-tight">
                                Document Compliance
                            </CardTitle>
                            <CardDescription className="text-sm font-medium">
                                {documentCompliance.total_documents} total documents tracked.
                            </CardDescription>
                        </div>
                        <ShieldCheck className="h-5 w-5 text-muted-foreground" />
                    </CardHeader>
                    <CardContent className="space-y-3">
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

function Metric({
    title,
    value,
    hint,
    icon: Icon,
    trend,
    glow,
    href,
}: {
    title: string;
    value: string;
    hint: string;
    icon?: React.ComponentType<{ className?: string }>;
    trend?: string;
    glow?: string;
    href?: string;
}): ReactElement {
    const content = (
        <Card
            className={`group border-white/5 bg-card/50 transition-all hover:bg-card/80 dark:bg-white/5 ${glow ?? ''} ${href ? 'cursor-pointer' : ''}`}
        >
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground transition-colors group-hover:text-foreground">
                    {title}
                </CardTitle>
                {Icon ? (
                    <Icon className="h-4 w-4 text-muted-foreground transition-colors group-hover:text-foreground" />
                ) : null}
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold tracking-tight">{value}</div>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                    <p className="text-xs text-muted-foreground">{hint}</p>
                    {trend ? (
                        <p className="rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary">
                            {trend}
                        </p>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );

    if (href) {
        return <a href={href}>{content}</a>;
    }

    return content;
}

function StatusBar({
    label,
    value,
    total,
    color,
    href,
}: {
    label: string;
    value: number;
    total: number;
    color: string;
    href: string;
}): ReactElement {
    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;

    return (
        <a
            href={href}
            className="group flex flex-col gap-1.5 rounded-xl border border-white/5 bg-white/5 p-3 transition-all hover:bg-white/10"
        >
            <div className="flex items-center justify-between text-sm">
                <span className="font-semibold transition-colors group-hover:text-primary">
                    {label}
                </span>
                <div className="flex items-center gap-2">
                    <span className="font-bold tabular-nums">{value.toLocaleString()}</span>
                    <span className="text-[10px] font-medium text-muted-foreground">
                        {percentage}%
                    </span>
                </div>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-white/10">
                <div
                    className={`h-full rounded-full transition-all ${color}`}
                    style={{ width: `${percentage}%` }}
                />
            </div>
        </a>
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
        <a
            href={href}
            className="group flex items-center justify-between gap-4 rounded-xl border border-white/5 bg-white/5 p-3 transition-all hover:bg-white/10"
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
                    className={`text-sm font-bold tabular-nums ${urgent ? 'text-destructive' : ''}`}
                >
                    {value}
                </div>
                <div
                    className={`h-1.5 w-1.5 rounded-full ${urgent && value !== '0' ? 'animate-pulse bg-destructive' : 'bg-primary'}`}
                />
                <ArrowUpRight className="h-3 w-3 text-muted-foreground opacity-0 transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:opacity-100" />
            </div>
        </a>
    );
}
