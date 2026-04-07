import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { AnalyticsChart } from './components/analytics-chart';
import { 
    Users, 
    UserPlus, 
    CalendarOff, 
    ClipboardCheck, 
    CreditCard, 
    IdCard, 
    Banknote, 
    ShieldCheck,
    ArrowUpRight,
    Plus,
    LayoutGrid,
    Search as SearchIcon,
    CheckCircle2
} from 'lucide-react';

export function DashboardContent() {
    const placeholder = (key: string) =>
        `${dashboard.url()}?module=${encodeURIComponent(key)}`;

    return (
        <>
            <Main>
                <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                    <div className="space-y-1.5">
                        <div className="flex items-center gap-2 mb-1">
                            <span className="flex h-2 w-2 rounded-full bg-primary animate-pulse" />
                            <span className="text-[10px] font-bold uppercase tracking-[0.2em] text-muted-foreground/80">
                                Real-time Intelligence
                            </span>
                        </div>
                        <h1 className="text-4xl font-extrabold tracking-tight bg-gradient-to-br from-foreground to-foreground/50 bg-clip-text text-transparent">
                            HR Dashboard
                        </h1>
                        <p className="text-sm text-muted-foreground/80 font-medium">
                            Synthesized overview of your organizational health and compliance.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Button variant="outline" className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10" asChild>
                            <a href={placeholder('employees.index')}>
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

                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                    <Metric
                        title="Headcount"
                        value="1,284"
                        hint="Active employees"
                        icon={Users}
                        trend="+12% from last month"
                        glow="glow-primary"
                    />
                    <Metric
                        title="New hires"
                        value="24"
                        hint="Last 30 days"
                        icon={UserPlus}
                        trend="+3 new today"
                        glow="glow-green"
                    />
                    <Metric
                        title="On leave"
                        value="12"
                        hint="Approved today"
                        icon={CalendarOff}
                        trend="2 emergency"
                        glow="glow-orange"
                    />
                    <Metric
                        title="Action items"
                        value="8"
                        hint="Pending requests"
                        icon={ClipboardCheck}
                        trend="4 high priority"
                        glow="glow-blue"
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-3 mb-6">
                    <Card className="border-white/5 bg-white/5 backdrop-blur-xl lg:col-span-2">
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <div>
                                <CardTitle className="text-xl font-bold tracking-tight">Growth & Headcount</CardTitle>
                                <CardDescription className="text-sm font-medium">
                                    Year-to-date workforce trends.
                                </CardDescription>
                            </div>
                            <div className="flex items-center gap-2">
                                <div className="flex items-center gap-1.5 px-3 py-1 rounded-full bg-primary/10 border border-primary/20">
                                    <div className="h-2 w-2 rounded-full bg-primary" />
                                    <span className="text-[10px] font-bold uppercase text-primary tracking-wider">Growth</span>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="pt-4">
                            <AnalyticsChart />
                        </CardContent>
                    </Card>

                    <Card className="border-white/5 bg-white/5 backdrop-blur-xl shrink-0 overflow-hidden relative flex flex-col">
                        <div className="absolute -top-12 -right-12 p-8 opacity-5 pointer-events-none">
                            <LayoutGrid className="h-64 w-64 rotate-12 text-white" />
                        </div>
                        <CardHeader>
                            <CardTitle className="text-xl font-bold tracking-tight">Quick Actions</CardTitle>
                            <CardDescription className="text-sm font-medium">
                                Accelerate your workflow.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 relative z-10 flex-1">
                            <ActionCard
                                title="Create employee"
                                description="Add a new employee record"
                                href={placeholder('quick-actions.create-employee')}
                            />
                            <ActionCard
                                title="New job posting"
                                description="Open a new vacancy"
                                href={placeholder('quick-actions.create-job-posting')}
                            />
                            <ActionCard
                                title="Payroll period"
                                description="Start a new payroll run"
                                href={placeholder('quick-actions.create-payroll-period')}
                            />
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="border-white/5 bg-white/5 backdrop-blur-xl">
                        <CardHeader className="pb-3">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-lg font-bold tracking-tight">Approvals</CardTitle>
                                <CheckCircle2 className="h-4 w-4 text-muted-foreground" />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <AtGlanceItem
                                title="Leave requests"
                                subtitle="Pending review"
                                href={placeholder('leave.requests')}
                                value="5"
                            />
                            <AtGlanceItem
                                title="Salary adjustments"
                                subtitle="Awaiting approval"
                                href={placeholder('payroll.adjustments')}
                                value="2"
                            />
                            <AtGlanceItem
                                title="Expense claims"
                                subtitle="Submitted for payout"
                                href={placeholder('expenses.claims')}
                                value="0"
                            />
                        </CardContent>
                    </Card>

                    <Card className="border-white/5 bg-white/5 backdrop-blur-xl">
                        <CardHeader className="pb-3">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-lg font-bold tracking-tight">Compliance</CardTitle>
                                <ShieldCheck className="h-4 w-4 text-muted-foreground" />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <AtGlanceItem
                                title="Visa expiry"
                                subtitle="Next 30 days"
                                href={placeholder('compliance.visas')}
                                value="15"
                            />
                            <AtGlanceItem
                                title="Emirates ID"
                                subtitle="In progress"
                                href={placeholder('compliance.eids')}
                                value="22"
                            />
                            <AtGlanceItem
                                title="Labor Cards"
                                subtitle="Expiring soon"
                                href={placeholder('compliance.labor')}
                                value="8"
                            />
                        </CardContent>
                    </Card>

                    <Card className="border-white/5 bg-white/5 backdrop-blur-xl">
                        <CardHeader className="pb-3">
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-lg font-bold tracking-tight">Payroll</CardTitle>
                                <Banknote className="h-4 w-4 text-muted-foreground" />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <AtGlanceItem
                                title="Current cycle"
                                subtitle="March 2026"
                                href={placeholder('payroll.current')}
                                value="Processing"
                            />
                            <AtGlanceItem
                                title="WPS Status"
                                subtitle="Latest submission"
                                href={placeholder('payroll.wps')}
                                value="Compliant"
                            />
                            <AtGlanceItem
                                title="Exceptions"
                                subtitle="Requires attention"
                                href={placeholder('payroll.exceptions')}
                                value="3"
                            />
                        </CardContent>
                    </Card>
                </div>
        </Main>
        </>
    );
}

function Metric({
    title,
    value,
    hint,
    icon: Icon,
    trend,
    glow,
}: {
    title: string;
    value: string;
    hint: string;
    icon?: any;
    trend?: string;
    glow?: string;
}) {
    return (
        <Card className={`group border-white/5 bg-card/50 transition-all hover:bg-card/80 dark:bg-white/5 ${glow}`}>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground group-hover:text-foreground transition-colors">
                    {title}
                </CardTitle>
                {Icon && <Icon className="h-4 w-4 text-muted-foreground group-hover:text-foreground transition-colors" />}
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold tracking-tight">
                    {value}
                </div>
                <div className="mt-1 flex items-center gap-2">
                    <p className="text-xs text-muted-foreground">{hint}</p>
                    {trend && (
                        <p className="text-[10px] font-medium text-primary bg-primary/10 px-1.5 py-0.5 rounded-full">
                            {trend}
                        </p>
                    )}
                </div>
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
            className="group flex flex-col gap-2 rounded-xl border border-white/5 bg-white/5 p-4 transition-all hover:bg-white/10 hover:shadow-lg dark:hover:shadow-primary/5"
        >
            <div className="flex items-center justify-between">
                <div className="text-sm font-semibold">{title}</div>
                <ArrowUpRight className="h-4 w-4 text-muted-foreground opacity-0 transition-all group-hover:opacity-100 group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
            </div>
            <div className="text-xs text-muted-foreground line-clamp-2">
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
            className="group flex items-center justify-between gap-4 rounded-xl border border-white/5 bg-white/5 p-3 transition-all hover:bg-white/10"
        >
            <div className="min-w-0">
                <div className="truncate text-sm font-semibold group-hover:text-primary transition-colors">{title}</div>
                <div className="truncate text-[10px] uppercase tracking-wider text-muted-foreground font-medium">
                    {subtitle}
                </div>
            </div>
            <div className="flex items-center gap-2">
                <div className="text-sm font-bold tabular-nums">{value === "—" ? "0" : value}</div>
                <div className="h-1.5 w-1.5 rounded-full bg-primary animate-pulse" />
            </div>
        </a>
    );
}

function PlaceholderRow({ label }: { label: string }) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-xl border border-white/5 bg-white/5 p-4 transition-all hover:bg-white/10">
            <div className="text-sm font-medium">{label}</div>
            <div className="text-sm font-bold tabular-nums text-muted-foreground">—</div>
        </div>
    );
}

