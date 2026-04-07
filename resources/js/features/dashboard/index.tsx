import { ConfigDrawer } from '@/components/config-drawer';
import { Header } from '@/components/layout/header';
import { Main } from '@/components/layout/main';
import { TopNav } from '@/components/layout/top-nav';
import { ProfileDropdown } from '@/components/profile-dropdown';
import { Search } from '@/components/search';
import { ThemeSwitch } from '@/components/theme-switch';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { dashboard } from '@/routes';

export function DashboardContent() {
    const placeholder = (key: string) =>
        `${dashboard.url()}?module=${encodeURIComponent(key)}`;

    return (
        <>
            <Header>
                <TopNav links={topNav(placeholder)} />
                <div className="ms-auto flex items-center space-x-4">
                    <Search />
                    <ThemeSwitch />
                    <ConfigDrawer />
                    <ProfileDropdown />
                </div>
            </Header>

            <Main>
                <div className="mb-6 flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            HR Dashboard
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Overview, approvals, and compliance signals.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="secondary" asChild>
                            <a href={placeholder('employees.index')}>
                                Employee directory
                            </a>
                        </Button>
                        <Button asChild>
                            <a href={placeholder('quick-actions.create-employee')}>
                                Create employee
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Metric
                        title="Headcount"
                        value="—"
                        hint="Active employees"
                    />
                    <Metric
                        title="New hires"
                        value="—"
                        hint="Last 30 days"
                    />
                    <Metric
                        title="On leave today"
                        value="—"
                        hint="Approved leave"
                    />
                    <Metric
                        title="Pending approvals"
                        value="—"
                        hint="Leave • Payroll • Adjustments"
                    />
                    <Metric
                        title="Visa expiring"
                        value="—"
                        hint="Next 30 days"
                    />
                    <Metric
                        title="Emirates ID expiring"
                        value="—"
                        hint="Next 30 days"
                    />
                    <Metric
                        title="Payroll period"
                        value="—"
                        hint="Current cycle"
                    />
                    <Metric
                        title="WPS status"
                        value="—"
                        hint="Latest submission"
                    />
                </div>

                <Tabs
                    orientation="vertical"
                    defaultValue="overview"
                    className="space-y-4"
                >
                    <TabsList className="h-auto w-full justify-start gap-1 rounded-xl bg-muted/40 p-1.5">
                        <TabsTrigger
                            value="overview"
                            className="px-4 py-2"
                        >
                            Overview
                        </TabsTrigger>
                        <TabsTrigger
                            value="approvals"
                            className="px-4 py-2"
                        >
                            Approvals
                        </TabsTrigger>
                        <TabsTrigger
                            value="compliance"
                            className="px-4 py-2"
                        >
                            Compliance
                        </TabsTrigger>
                        <TabsTrigger
                            value="payroll"
                            className="px-4 py-2"
                        >
                            Payroll
                        </TabsTrigger>
                    </TabsList>
                    <TabsContent value="overview" className="space-y-4">
                        <div className="grid gap-4 lg:grid-cols-3">
                            <Card className="lg:col-span-2">
                                <CardHeader>
                                    <CardTitle>Quick actions</CardTitle>
                                    <CardDescription>
                                        Common tasks across modules
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-3 sm:grid-cols-2">
                                    <ActionCard
                                        title="Create employee"
                                        description="Add a new employee record"
                                        href={placeholder('quick-actions.create-employee')}
                                    />
                                    <ActionCard
                                        title="Create job posting"
                                        description="Open a new vacancy"
                                        href={placeholder('quick-actions.create-job-posting')}
                                    />
                                    <ActionCard
                                        title="New leave request"
                                        description="Submit time off request"
                                        href={placeholder('quick-actions.new-leave-request')}
                                    />
                                    <ActionCard
                                        title="Create payroll period"
                                        description="Start a new payroll run"
                                        href={placeholder('quick-actions.create-payroll-period')}
                                    />
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader>
                                    <CardTitle>At a glance</CardTitle>
                                    <CardDescription>
                                        What needs attention today
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <AtGlanceItem
                                        title="Leave approvals"
                                        subtitle="Pending requests"
                                        href={placeholder('leave.requests')}
                                        value="—"
                                    />
                                    <AtGlanceItem
                                        title="Salary adjustments"
                                        subtitle="Awaiting approval"
                                        href={placeholder('payroll.adjustments')}
                                        value="—"
                                    />
                                    <AtGlanceItem
                                        title="Compliance"
                                        subtitle="Expiring documents"
                                        href={placeholder('employees.documents')}
                                        value="—"
                                    />
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                    <TabsContent value="approvals" className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Approvals</CardTitle>
                                <CardDescription>
                                    Leave, payroll, and adjustment approvals
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <PlaceholderRow label="Leave requests pending" />
                                <PlaceholderRow label="Salary adjustments pending" />
                                <PlaceholderRow label="Payroll period approvals" />
                            </CardContent>
                        </Card>
                    </TabsContent>
                    <TabsContent value="compliance" className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Compliance</CardTitle>
                                <CardDescription>
                                    UAE document expiry signals
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <PlaceholderRow label="Visas expiring in 30 days" />
                                <PlaceholderRow label="Emirates IDs expiring in 30 days" />
                                <PlaceholderRow label="Passports expiring in 90 days" />
                            </CardContent>
                        </Card>
                    </TabsContent>
                    <TabsContent value="payroll" className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Payroll</CardTitle>
                                <CardDescription>
                                    Current cycle and WPS status
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <PlaceholderRow label="Current period" />
                                <PlaceholderRow label="Records generated" />
                                <PlaceholderRow label="WPS submission status" />
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </Main>
        </>
    );
}

function Metric({
    title,
    value,
    hint,
}: {
    title: string;
    value: string;
    hint: string;
}) {
    return (
        <Card>
            <CardHeader className="space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-semibold tabular-nums">
                    {value}
                </div>
                <p className="text-xs text-muted-foreground">{hint}</p>
            </CardContent>
        </Card>
    );
}

function ActionCard({
    title,
    description,
    href,
}: {
    title: string;
    description: string;
    href: string;
}) {
    return (
        <a
            href={href}
            className="rounded-lg border bg-card p-4 transition-colors hover:bg-muted/40"
        >
            <div className="text-sm font-semibold">{title}</div>
            <div className="mt-1 text-sm text-muted-foreground">
                {description}
            </div>
        </a>
    );
}

function AtGlanceItem({
    title,
    subtitle,
    value,
    href,
}: {
    title: string;
    subtitle: string;
    value: string;
    href: string;
}) {
    return (
        <a
            href={href}
            className="flex items-center justify-between gap-4 rounded-lg border bg-card p-3 transition-colors hover:bg-muted/40"
        >
            <div className="min-w-0">
                <div className="truncate text-sm font-semibold">{title}</div>
                <div className="truncate text-xs text-muted-foreground">
                    {subtitle}
                </div>
            </div>
            <div className="text-sm font-semibold tabular-nums">{value}</div>
        </a>
    );
}

function PlaceholderRow({ label }: { label: string }) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border bg-muted/20 p-3">
            <div className="text-sm">{label}</div>
            <div className="text-sm font-semibold tabular-nums">—</div>
        </div>
    );
}

const topNav = (placeholder: (key: string) => string) => [
    {
        title: 'Employees',
        href: placeholder('employees.index'),
        isActive: false,
        disabled: false,
    },
    {
        title: 'Recruitment',
        href: placeholder('recruitment.job-postings'),
        isActive: false,
        disabled: false,
    },
    {
        title: 'Attendance',
        href: placeholder('attendance.records'),
        isActive: false,
        disabled: false,
    },
    {
        title: 'Leave',
        href: placeholder('leave.requests'),
        isActive: false,
        disabled: false,
    },
    {
        title: 'Payroll',
        href: placeholder('payroll.periods'),
        isActive: false,
        disabled: false,
    },
];
