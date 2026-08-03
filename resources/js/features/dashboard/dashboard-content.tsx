import { Link, router } from '@inertiajs/react';
import {
    Activity,
    Anchor,
    ArrowUpRight,
    Award,
    CalendarOff,
    FilePen,
    FileText,
    Landmark,
    Megaphone,
    PieChart,
    TrendingUp,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { index as leaveRequestsIndex } from '@/routes/attendance/leave-requests';
import { index as attendanceIndex } from '@/routes/attendance/records';
import {
    bankAccounts,
    contracts,
    documents,
    employees,
    training,
} from '@/routes/organization';
import { index as announcementsIndex } from '@/routes/organization/announcements';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';
import { index as payrollIndex } from '@/routes/organization/payroll';
import { DistributionBarChart } from './charts/distribution-bar-chart';
import { WorkforceTrendChart } from './charts/workforce-trend-chart';
import { AttentionCenter } from './components/attention-center';
import { DashboardHeader } from './components/dashboard-header';
import { QuickActions } from './components/quick-actions';
import type {
    AnnouncementsDashboardSummary,
    AttendanceAnalytics,
    BankAccountsDashboardSummary,
    ContractsDashboardSummary,
    CrewDashboardSummary,
    DashboardProps,
    DocumentCompliance,
    EmployeeAnalytics,
    LeaveDashboardSummary,
    OrganizationSnapshot,
    PayrollDashboardSummary,
    TrainingDashboardSummary,
} from './dashboard-types';
import { ActivitySection } from './sections/activity-section';
import { PersonalSection } from './sections/personal-section';

// ─── Module Tile ──────────────────────────────────────────────────────────────

type Stat = { label: string; value: string | number; highlight?: boolean };

type ModuleTone =
    | 'amber'
    | 'blue'
    | 'emerald'
    | 'green'
    | 'indigo'
    | 'orange'
    | 'pink'
    | 'sky'
    | 'teal'
    | 'violet';

const moduleToneStyles: Record<
    ModuleTone,
    { icon: string; surface: string; border: string }
> = {
    amber: {
        icon: 'text-amber-600 dark:text-amber-400',
        surface: 'bg-amber-500/10',
        border: 'group-hover:border-amber-500/30',
    },
    blue: {
        icon: 'text-blue-600 dark:text-blue-400',
        surface: 'bg-blue-500/10',
        border: 'group-hover:border-blue-500/30',
    },
    emerald: {
        icon: 'text-emerald-600 dark:text-emerald-400',
        surface: 'bg-emerald-500/10',
        border: 'group-hover:border-emerald-500/30',
    },
    green: {
        icon: 'text-green-600 dark:text-green-400',
        surface: 'bg-green-500/10',
        border: 'group-hover:border-green-500/30',
    },
    indigo: {
        icon: 'text-indigo-600 dark:text-indigo-400',
        surface: 'bg-indigo-500/10',
        border: 'group-hover:border-indigo-500/30',
    },
    orange: {
        icon: 'text-orange-600 dark:text-orange-400',
        surface: 'bg-orange-500/10',
        border: 'group-hover:border-orange-500/30',
    },
    pink: {
        icon: 'text-pink-600 dark:text-pink-400',
        surface: 'bg-pink-500/10',
        border: 'group-hover:border-pink-500/30',
    },
    sky: {
        icon: 'text-sky-600 dark:text-sky-400',
        surface: 'bg-sky-500/10',
        border: 'group-hover:border-sky-500/30',
    },
    teal: {
        icon: 'text-teal-600 dark:text-teal-400',
        surface: 'bg-teal-500/10',
        border: 'group-hover:border-teal-500/30',
    },
    violet: {
        icon: 'text-violet-600 dark:text-violet-400',
        surface: 'bg-violet-500/10',
        border: 'group-hover:border-violet-500/30',
    },
};

type ModuleTileProps = {
    icon: React.ElementType;
    name: string;
    href: string;
    tone: ModuleTone;
    primary: { value: string | number; label: string };
    stats: Stat[];
};

function ModuleTile({
    icon: Icon,
    name,
    href,
    tone,
    primary,
    stats,
}: ModuleTileProps) {
    const toneStyles = moduleToneStyles[tone];

    return (
        <Link
            href={href}
            aria-label={`Open ${name}`}
            className="group block h-full rounded-2xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <div
                className={`flex h-full min-h-44 flex-col rounded-2xl border border-border/70 bg-card/90 p-4 shadow-sm transition-all duration-200 group-hover:-translate-y-0.5 group-hover:shadow-lg dark:bg-card/70 ${toneStyles.border}`}
            >
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5">
                        <div
                            className={`flex h-9 w-9 items-center justify-center rounded-xl ${toneStyles.surface}`}
                        >
                            <Icon
                                className={`h-4.5 w-4.5 ${toneStyles.icon}`}
                            />
                        </div>
                        <span className="text-xs font-semibold text-foreground">
                            {name}
                        </span>
                    </div>
                    <ArrowUpRight className="h-4 w-4 text-muted-foreground/50 transition-all group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-foreground" />
                </div>

                <div className="mt-5">
                    <span className="block text-3xl font-semibold tracking-tight text-foreground tabular-nums">
                        {primary.value}
                    </span>
                    <span className="mt-0.5 block text-xs text-muted-foreground">
                        {primary.label}
                    </span>
                </div>

                <dl className="mt-auto flex flex-wrap gap-x-3 gap-y-1.5 border-t border-border/50 pt-3">
                    {stats.map((s) => (
                        <div
                            key={s.label}
                            className={`flex items-baseline gap-1 text-[11px] tabular-nums ${s.highlight ? 'text-foreground' : 'text-muted-foreground'}`}
                        >
                            <dd className="font-semibold">{s.value}</dd>
                            <dt>{s.label}</dt>
                        </div>
                    ))}
                </dl>
            </div>
        </Link>
    );
}

// ─── Module helpers ───────────────────────────────────────────────────────────

function WorkforceTile({
    analytics,
    snapshot,
}: {
    analytics: EmployeeAnalytics;
    snapshot?: OrganizationSnapshot;
}) {
    return (
        <ModuleTile
            icon={Users}
            name="Workforce"
            href={employees.url()}
            tone="blue"
            primary={{ value: analytics.active, label: 'active employees' }}
            stats={[
                { label: 'total', value: analytics.total },
                {
                    label: 'new this month',
                    value: analytics.new_hires_this_month,
                    highlight: true,
                },
                ...(snapshot
                    ? [{ label: 'depts', value: snapshot.departments }]
                    : []),
            ]}
        />
    );
}

function AttendanceTile({ analytics }: { analytics: AttendanceAnalytics }) {
    const rate =
        analytics.active_employees > 0
            ? Math.round(
                  (analytics.present_today / analytics.active_employees) * 100,
              )
            : 0;

    return (
        <ModuleTile
            icon={Activity}
            name="Attendance"
            href={attendanceIndex.url()}
            tone="teal"
            primary={{ value: `${rate}%`, label: 'attendance rate' }}
            stats={[
                { label: 'present', value: analytics.present_today },
                { label: 'check-ins', value: analytics.check_ins_today },
                {
                    label: 'late',
                    value: analytics.late_today,
                    highlight: analytics.late_today > 0,
                },
            ]}
        />
    );
}

function DocumentsTile({ compliance }: { compliance: DocumentCompliance }) {
    return (
        <ModuleTile
            icon={FileText}
            name="Documents"
            href={documents.url()}
            tone="violet"
            primary={{
                value: `${compliance.compliance_rate}%`,
                label: 'compliance',
            }}
            stats={[
                { label: 'total', value: compliance.total_documents },
                {
                    label: 'expired',
                    value: compliance.expired,
                    highlight: compliance.expired > 0,
                },
                {
                    label: 'expiring 7d',
                    value: compliance.expiring_7,
                    highlight: compliance.expiring_7 > 0,
                },
            ]}
        />
    );
}

function LeaveTile({ summary }: { summary: LeaveDashboardSummary }) {
    return (
        <ModuleTile
            icon={CalendarOff}
            name="Leave"
            href={leaveRequestsIndex.url()}
            tone="emerald"
            primary={{ value: summary.on_leave_today, label: 'on leave today' }}
            stats={[
                {
                    label: 'pending',
                    value: summary.pending_requests,
                    highlight: summary.pending_requests > 0,
                },
                {
                    label: 'need approval',
                    value: summary.awaiting_my_approval,
                    highlight: summary.awaiting_my_approval > 0,
                },
                { label: 'this week', value: summary.upcoming_this_week },
            ]}
        />
    );
}

function ContractsTile({ summary }: { summary: ContractsDashboardSummary }) {
    return (
        <ModuleTile
            icon={FilePen}
            name="Contracts"
            href={contracts.url()}
            tone="orange"
            primary={{ value: summary.active, label: 'active contracts' }}
            stats={[
                {
                    label: 'ending 30d',
                    value: summary.ending_30,
                    highlight: summary.ending_30 > 0,
                },
                {
                    label: 'no contract',
                    value: summary.no_contract_employees,
                    highlight: summary.no_contract_employees > 0,
                },
                { label: 'ended', value: summary.ended },
            ]}
        />
    );
}

function TrainingTile({ summary }: { summary: TrainingDashboardSummary }) {
    return (
        <ModuleTile
            icon={Award}
            name="Training"
            href={training.url()}
            tone="amber"
            primary={{ value: summary.total, label: 'certificates' }}
            stats={[
                {
                    label: 'expired',
                    value: summary.expired,
                    highlight: summary.expired > 0,
                },
                {
                    label: 'expiring 30d',
                    value: summary.expiring_30,
                    highlight: summary.expiring_30 > 0,
                },
                {
                    label: 'expiring 7d',
                    value: summary.expiring_7,
                    highlight: summary.expiring_7 > 0,
                },
            ]}
        />
    );
}

function BankTile({ summary }: { summary: BankAccountsDashboardSummary }) {
    return (
        <ModuleTile
            icon={Landmark}
            name="Bank Accounts"
            href={bankAccounts.url()}
            tone="indigo"
            primary={{
                value: summary.total_bank_accounts,
                label: 'accounts linked',
            }}
            stats={[
                { label: 'primary', value: summary.primary_accounts },
                {
                    label: 'missing',
                    value: summary.no_account_employees,
                    highlight: summary.no_account_employees > 0,
                },
            ]}
        />
    );
}

function PayrollTile({ summary }: { summary: PayrollDashboardSummary }) {
    return (
        <ModuleTile
            icon={TrendingUp}
            name="Payroll"
            href={payrollIndex.url()}
            tone="green"
            primary={{
                value: summary.last_paid_period_name ?? '—',
                label: 'last paid period',
            }}
            stats={[
                {
                    label: 'draft',
                    value: summary.draft_periods,
                    highlight: summary.draft_periods > 0,
                },
                {
                    label: 'processing',
                    value: summary.processing_periods,
                    highlight: summary.processing_periods > 0,
                },
                {
                    label: 'pending approval',
                    value: summary.awaiting_approval_periods,
                    highlight: summary.awaiting_approval_periods > 0,
                },
            ]}
        />
    );
}

function CrewTile({ summary }: { summary: CrewDashboardSummary }) {
    return (
        <ModuleTile
            icon={Anchor}
            name="Crew"
            href={crewPlanningIndex.url()}
            tone="sky"
            primary={{ value: summary.on_vessel, label: 'on vessel' }}
            stats={[
                { label: 'at home', value: summary.at_home },
                {
                    label: 'needs update',
                    value: summary.needs_update,
                    highlight: summary.needs_update > 0,
                },
                {
                    label: 'sign-off due',
                    value: summary.planned_signoffs_due,
                    highlight: summary.planned_signoffs_due > 0,
                },
            ]}
        />
    );
}

function AnnouncementsTile({
    summary,
}: {
    summary: AnnouncementsDashboardSummary;
}) {
    return (
        <ModuleTile
            icon={Megaphone}
            name="Announcements"
            href={announcementsIndex.url()}
            tone="pink"
            primary={{ value: summary.published, label: 'published' }}
            stats={[
                { label: 'scheduled', value: summary.scheduled },
                {
                    label: 'failed',
                    value: summary.failed_deliveries,
                    highlight: summary.failed_deliveries > 0,
                },
                { label: 'total', value: summary.total },
            ]}
        />
    );
}

// ─── Main Component ───────────────────────────────────────────────────────────

export function DashboardContent(props: DashboardProps) {
    const {
        personal_summary,
        personal_dashboard,
        attention_items = [],
        can,
        employee_analytics,
        organization_snapshot,
        document_compliance,
        attendance_analytics,
        leave_summary,
        contracts_summary,
        training_summary,
        bank_accounts_summary,
        payroll_summary,
        crew_summary,
        announcements_summary,
        audit_summary,
        workforce_trends = [],
        employees_by_department = [],
        employees_by_branch = [],
        recent_hires = [],
    } = props;

    const [lastUpdated, setLastUpdated] = useState<Date>(new Date());
    const [isRefreshing, setIsRefreshing] = useState<boolean>(false);

    const handleRefresh = useCallback(() => {
        setIsRefreshing(true);
        router.reload({
            onFinish: () => {
                setLastUpdated(new Date());
                setIsRefreshing(false);
            },
        });
    }, []);

    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({
                only: [
                    'attendance_analytics',
                    'leave_summary',
                    'attention_items',
                    'personal_dashboard',
                    'payroll_summary',
                    'crew_summary',
                ],
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setLastUpdated(new Date()),
            } as any);
        }, 60000);

        return () => clearInterval(interval);
    }, []);

    const hasModules =
        employee_analytics ||
        attendance_analytics ||
        document_compliance ||
        leave_summary ||
        contracts_summary ||
        training_summary ||
        bank_accounts_summary ||
        payroll_summary ||
        crew_summary ||
        announcements_summary;

    const hasCharts =
        workforce_trends.length > 0 ||
        employees_by_department.length > 0 ||
        employees_by_branch.length > 0;

    return (
        <div className="mx-auto w-full max-w-400 space-y-8 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            {/* Header & Quick Actions */}
            <div className="space-y-4">
                <DashboardHeader
                    personalSummary={personal_summary}
                    lastUpdated={lastUpdated}
                    isRefreshing={isRefreshing}
                    onRefresh={handleRefresh}
                />
                <QuickActions can={can} />
            </div>

            {/* Action-required items — compact list */}
            <AttentionCenter items={attention_items} />

            {/* Module Overview Grid */}
            {hasModules && (
                <section
                    className="space-y-4"
                    aria-labelledby="module-overview-heading"
                >
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h2
                                id="module-overview-heading"
                                className="text-lg font-semibold tracking-tight text-foreground"
                            >
                                Organization overview
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Key health indicators across your enabled
                                modules
                            </p>
                        </div>
                        <span className="text-xs text-muted-foreground">
                            Select a card to view details
                        </span>
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                        {employee_analytics && (
                            <WorkforceTile
                                analytics={employee_analytics}
                                snapshot={organization_snapshot}
                            />
                        )}
                        {attendance_analytics && (
                            <AttendanceTile analytics={attendance_analytics} />
                        )}
                        {document_compliance && (
                            <DocumentsTile compliance={document_compliance} />
                        )}
                        {leave_summary && <LeaveTile summary={leave_summary} />}
                        {contracts_summary && (
                            <ContractsTile summary={contracts_summary} />
                        )}
                        {training_summary && (
                            <TrainingTile summary={training_summary} />
                        )}
                        {bank_accounts_summary && (
                            <BankTile summary={bank_accounts_summary} />
                        )}
                        {payroll_summary && (
                            <PayrollTile summary={payroll_summary} />
                        )}
                        {crew_summary && <CrewTile summary={crew_summary} />}
                        {announcements_summary && (
                            <AnnouncementsTile
                                summary={announcements_summary}
                            />
                        )}
                    </div>
                </section>
            )}

            {/* Personal Dashboard */}
            <PersonalSection data={personal_dashboard} />

            {/* Workforce Charts */}
            {hasCharts && (
                <section className="space-y-4" aria-labelledby="trends-heading">
                    <div>
                        <h2
                            id="trends-heading"
                            className="text-lg font-semibold tracking-tight text-foreground"
                        >
                            Trends & distribution
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Workforce movement and team composition at a glance
                        </p>
                    </div>
                    <div className="grid gap-4 lg:grid-cols-2">
                        {workforce_trends.length > 0 && (
                            <div className="overflow-hidden rounded-2xl border border-border/70 bg-card/80 p-5 shadow-sm backdrop-blur-sm dark:bg-card/60">
                                <div className="mb-1 flex items-center gap-2.5">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <TrendingUp className="h-4 w-4" />
                                    </div>
                                    <span className="text-sm font-semibold text-foreground">
                                        Workforce & Hiring Trends
                                    </span>
                                </div>
                                <p className="mb-2 pl-10.5 text-xs text-muted-foreground">
                                    Headcount growth and monthly hires
                                </p>
                                <WorkforceTrendChart data={workforce_trends} />
                            </div>
                        )}

                        {(employees_by_department.length > 0 ||
                            employees_by_branch.length > 0) && (
                            <div className="space-y-4 overflow-hidden rounded-2xl border border-border/70 bg-card/80 p-5 shadow-sm backdrop-blur-sm dark:bg-card/60">
                                <div className="flex items-center gap-2.5">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <PieChart className="h-4 w-4" />
                                    </div>
                                    <span className="text-sm font-semibold text-foreground">
                                        Organization Distribution
                                    </span>
                                </div>
                                {employees_by_department.length > 0 && (
                                    <div className="space-y-2">
                                        <h4 className="text-[11px] font-bold tracking-wider text-muted-foreground uppercase">
                                            By Department
                                        </h4>
                                        <DistributionBarChart
                                            data={employees_by_department}
                                        />
                                    </div>
                                )}
                                {employees_by_branch.length > 0 && (
                                    <div className="space-y-2 border-t border-border/50 pt-4">
                                        <h4 className="text-[11px] font-bold tracking-wider text-muted-foreground uppercase">
                                            By Branch
                                        </h4>
                                        <DistributionBarChart
                                            data={employees_by_branch}
                                        />
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </section>
            )}

            {/* Recent Hires & Activity */}
            {(recent_hires.length > 0 || audit_summary) && (
                <section
                    className="space-y-4"
                    aria-labelledby="recent-activity-heading"
                >
                    <div>
                        <h2
                            id="recent-activity-heading"
                            className="text-lg font-semibold tracking-tight text-foreground"
                        >
                            Recent activity
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            New team members and the latest system events
                        </p>
                    </div>
                    <div className="grid gap-4 lg:grid-cols-2">
                        {recent_hires.length > 0 && (
                            <div className="overflow-hidden rounded-2xl border border-border/70 bg-card/80 shadow-sm backdrop-blur-sm dark:bg-card/60">
                                <div className="flex items-center justify-between border-b border-border/50 bg-muted/20 px-5 py-4">
                                    <div className="flex items-center gap-2">
                                        <Users className="h-4 w-4 text-primary" />
                                        <span className="text-sm font-semibold text-foreground">
                                            Recent Hires
                                        </span>
                                    </div>
                                    <Link
                                        href={employees.url()}
                                        className="text-xs font-medium text-primary hover:underline"
                                    >
                                        View all →
                                    </Link>
                                </div>
                                <div className="divide-y divide-border/30">
                                    {recent_hires.map((hire) => (
                                        <div
                                            key={hire.id}
                                            className="flex items-center justify-between gap-3 px-5 py-3.5 transition-colors hover:bg-muted/30"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-foreground">
                                                    {hire.name}
                                                </p>
                                                <p className="font-mono text-[11px] text-muted-foreground">
                                                    {hire.employee_no}
                                                </p>
                                            </div>
                                            <span className="shrink-0 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                                {hire.hired_at}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        <ActivitySection summary={audit_summary} />
                    </div>
                </section>
            )}
        </div>
    );
}
