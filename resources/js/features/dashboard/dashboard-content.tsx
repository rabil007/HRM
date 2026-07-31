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

type ModuleTileProps = {
    icon: React.ElementType;
    name: string;
    href: string;
    iconBg: string;
    iconColor: string;
    primary: { value: string | number; label: string };
    stats: Stat[];
};

function ModuleTile({ icon: Icon, name, href, iconBg, iconColor, primary, stats }: ModuleTileProps) {
    return (
        <Link href={href} className="group block">
            <div className="rounded-xl bg-card border border-border/50 p-4 shadow-sm hover:shadow-md hover:border-border/80 transition-all duration-200 h-full flex flex-col gap-3">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <div className={`rounded-lg p-1.5 ${iconBg}`}>
                            <Icon className={`h-4 w-4 ${iconColor}`} />
                        </div>
                        <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                            {name}
                        </span>
                    </div>
                    <ArrowUpRight className="h-3.5 w-3.5 text-muted-foreground/30 opacity-0 group-hover:opacity-100 transition-opacity" />
                </div>

                <div>
                    <span className="text-2xl font-bold tracking-tight text-foreground tabular-nums">
                        {primary.value}
                    </span>
                    <span className="text-xs text-muted-foreground ml-1.5">{primary.label}</span>
                </div>

                <div className="flex flex-wrap gap-x-3 gap-y-1 pt-1 border-t border-border/30">
                    {stats.map((s) => (
                        <span
                            key={s.label}
                            className={`text-[11px] tabular-nums ${s.highlight ? 'font-semibold text-foreground' : 'text-muted-foreground'}`}
                        >
                            <span className="font-bold">{s.value}</span> {s.label}
                        </span>
                    ))}
                </div>
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
            iconBg="bg-blue-500/10"
            iconColor="text-blue-500"
            primary={{ value: analytics.active, label: 'active employees' }}
            stats={[
                { label: 'total', value: analytics.total },
                { label: 'new this month', value: analytics.new_hires_this_month, highlight: true },
                ...(snapshot ? [{ label: 'depts', value: snapshot.departments }] : []),
            ]}
        />
    );
}

function AttendanceTile({ analytics }: { analytics: AttendanceAnalytics }) {
    const rate =
        analytics.active_employees > 0
            ? Math.round((analytics.present_today / analytics.active_employees) * 100)
            : 0;

    return (
        <ModuleTile
            icon={Activity}
            name="Attendance"
            href={attendanceIndex.url()}
            iconBg="bg-teal-500/10"
            iconColor="text-teal-500"
            primary={{ value: `${rate}%`, label: 'attendance rate' }}
            stats={[
                { label: 'present', value: analytics.present_today },
                { label: 'check-ins', value: analytics.check_ins_today },
                { label: 'late', value: analytics.late_today, highlight: analytics.late_today > 0 },
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
            iconBg="bg-violet-500/10"
            iconColor="text-violet-500"
            primary={{ value: `${compliance.compliance_rate}%`, label: 'compliance' }}
            stats={[
                { label: 'total', value: compliance.total_documents },
                { label: 'expired', value: compliance.expired, highlight: compliance.expired > 0 },
                { label: 'expiring 7d', value: compliance.expiring_7, highlight: compliance.expiring_7 > 0 },
            ]}
        />
    );
}

function LeaveTile({ summary }: { summary: LeaveDashboardSummary }) {
    return (
        <ModuleTile
            icon={CalendarOff}
            name="Leave"
            href="#"
            iconBg="bg-emerald-500/10"
            iconColor="text-emerald-500"
            primary={{ value: summary.on_leave_today, label: 'on leave today' }}
            stats={[
                { label: 'pending', value: summary.pending_requests, highlight: summary.pending_requests > 0 },
                { label: 'need approval', value: summary.awaiting_my_approval, highlight: summary.awaiting_my_approval > 0 },
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
            iconBg="bg-orange-500/10"
            iconColor="text-orange-500"
            primary={{ value: summary.active, label: 'active contracts' }}
            stats={[
                { label: 'ending 30d', value: summary.ending_30, highlight: summary.ending_30 > 0 },
                { label: 'no contract', value: summary.no_contract_employees, highlight: summary.no_contract_employees > 0 },
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
            iconBg="bg-amber-500/10"
            iconColor="text-amber-500"
            primary={{ value: summary.total, label: 'certificates' }}
            stats={[
                { label: 'expired', value: summary.expired, highlight: summary.expired > 0 },
                { label: 'expiring 30d', value: summary.expiring_30, highlight: summary.expiring_30 > 0 },
                { label: 'expiring 7d', value: summary.expiring_7, highlight: summary.expiring_7 > 0 },
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
            iconBg="bg-indigo-500/10"
            iconColor="text-indigo-500"
            primary={{ value: summary.total_bank_accounts, label: 'accounts linked' }}
            stats={[
                { label: 'primary', value: summary.primary_accounts },
                { label: 'missing', value: summary.no_account_employees, highlight: summary.no_account_employees > 0 },
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
            iconBg="bg-green-500/10"
            iconColor="text-green-500"
            primary={{
                value: summary.last_paid_period_name ?? '—',
                label: 'last paid period',
            }}
            stats={[
                { label: 'draft', value: summary.draft_periods, highlight: summary.draft_periods > 0 },
                { label: 'processing', value: summary.processing_periods, highlight: summary.processing_periods > 0 },
                { label: 'pending approval', value: summary.awaiting_approval_periods, highlight: summary.awaiting_approval_periods > 0 },
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
            iconBg="bg-sky-500/10"
            iconColor="text-sky-500"
            primary={{ value: summary.on_vessel, label: 'on vessel' }}
            stats={[
                { label: 'at home', value: summary.at_home },
                { label: 'needs update', value: summary.needs_update, highlight: summary.needs_update > 0 },
                { label: 'sign-off due', value: summary.planned_signoffs_due, highlight: summary.planned_signoffs_due > 0 },
            ]}
        />
    );
}

function AnnouncementsTile({ summary }: { summary: AnnouncementsDashboardSummary }) {
    return (
        <ModuleTile
            icon={Megaphone}
            name="Announcements"
            href={announcementsIndex.url()}
            iconBg="bg-pink-500/10"
            iconColor="text-pink-500"
            primary={{ value: summary.published, label: 'published' }}
            stats={[
                { label: 'scheduled', value: summary.scheduled },
                { label: 'failed', value: summary.failed_deliveries, highlight: summary.failed_deliveries > 0 },
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
        <div className="space-y-6 px-4 py-6 sm:px-6">
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
                <section className="space-y-3">
                    <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground px-0.5">
                        Overview
                    </h2>
                    <div className="grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
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
                        {contracts_summary && <ContractsTile summary={contracts_summary} />}
                        {training_summary && <TrainingTile summary={training_summary} />}
                        {bank_accounts_summary && <BankTile summary={bank_accounts_summary} />}
                        {payroll_summary && <PayrollTile summary={payroll_summary} />}
                        {crew_summary && <CrewTile summary={crew_summary} />}
                        {announcements_summary && (
                            <AnnouncementsTile summary={announcements_summary} />
                        )}
                    </div>
                </section>
            )}

            {/* Personal Dashboard */}
            <PersonalSection data={personal_dashboard} />

            {/* Workforce Charts */}
            {hasCharts && (
                <section className="space-y-3">
                    <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground px-0.5">
                        Trends & Distribution
                    </h2>
                    <div className="grid gap-4 lg:grid-cols-2">
                        {workforce_trends.length > 0 && (
                            <div className="rounded-xl bg-card border border-border/50 p-4 shadow-sm space-y-2">
                                <div className="flex items-center gap-2">
                                    <TrendingUp className="h-4 w-4 text-primary" />
                                    <span className="text-sm font-semibold text-foreground">
                                        Workforce & Hiring Trends
                                    </span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Headcount growth and monthly hires
                                </p>
                                <WorkforceTrendChart data={workforce_trends} />
                            </div>
                        )}

                        {(employees_by_department.length > 0 ||
                            employees_by_branch.length > 0) && (
                            <div className="rounded-xl bg-card border border-border/50 p-4 shadow-sm space-y-4">
                                <div className="flex items-center gap-2">
                                    <PieChart className="h-4 w-4 text-primary" />
                                    <span className="text-sm font-semibold text-foreground">
                                        Organization Distribution
                                    </span>
                                </div>
                                {employees_by_department.length > 0 && (
                                    <div className="space-y-2">
                                        <h4 className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                                            By Department
                                        </h4>
                                        <DistributionBarChart data={employees_by_department} />
                                    </div>
                                )}
                                {employees_by_branch.length > 0 && (
                                    <div className="space-y-2 pt-3 border-t border-border/40">
                                        <h4 className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
                                            By Branch
                                        </h4>
                                        <DistributionBarChart data={employees_by_branch} />
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </section>
            )}

            {/* Recent Hires & Activity */}
            {(recent_hires.length > 0 || audit_summary) && (
                <section className="space-y-3">
                    <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground px-0.5">
                        Recent Activity
                    </h2>
                    <div className="grid gap-4 lg:grid-cols-2">
                        {recent_hires.length > 0 && (
                            <div className="rounded-xl bg-card border border-border/50 shadow-sm overflow-hidden">
                                <div className="flex items-center justify-between px-4 py-3 border-b border-border/40 bg-muted/20">
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
                                            className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-muted/20 transition-colors"
                                        >
                                            <div className="min-w-0">
                                                <p className="text-sm font-semibold text-foreground truncate">
                                                    {hire.name}
                                                </p>
                                                <p className="text-[11px] text-muted-foreground font-mono">
                                                    {hire.employee_no}
                                                </p>
                                            </div>
                                            <span className="text-[11px] font-medium text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-full shrink-0">
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
