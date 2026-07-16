import { Link, usePoll } from '@inertiajs/react';
import {
    AlertTriangle,
    Anchor,
    ArrowUpRight,
    BarChart3,
    CalendarDays,
    CalendarRange,
    ChevronRight,
    Clock,
    Home,
    LayoutDashboard,
    Ship,
    Settings,
    Users,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { CrewOperationsDeploymentSummaryCards } from '@/features/organization/crew-operations/components/deployment-summary-cards';
import { DeploymentTrendChart } from '@/features/organization/crew-operations/components/deployment-trend-chart';
import { ManningGapsCard } from '@/features/organization/crew-operations/components/manning-gaps-card';
import type { CrewOperationsDashboardProps } from '@/features/organization/crew-operations/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import crewOperations from '@/routes/organization/crew-operations';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';
import { index as vesselManningIndex } from '@/routes/organization/vessel-manning';

export type { CrewOperationsDashboardProps } from '@/features/organization/crew-operations/types';

const SEVERITY_BADGE: Record<string, 'destructive' | 'warning' | 'secondary'> =
    {
        critical: 'destructive',
        warning: 'warning',
        info: 'secondary',
    };

export function CrewOperationsDashboardContent({
    deployment_summary: deploymentSummary,
    alert_counts: alertCounts,
    attention_items: attentionItems,
    manning_gaps: manningGaps,
    deployment_trends: deploymentTrends,
    upcoming_planning: upcomingPlanning,
    pool_snapshot: poolSnapshot,
    recent_activity: recentActivity,
    max_home_days: maxHomeDays,
    can,
    can_view_audit: canViewAudit,
}: CrewOperationsDashboardProps): ReactElement {
    usePoll(60_000, {
        only: [
            'alert_counts',
            'deployment_summary',
            'attention_items',
            'manning_gaps',
            'deployment_trends',
        ],
    });

    const today = new Date().toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    const hasUrgentAlerts =
        alertCounts.needs_update +
            alertCounts.overdue_home +
            alertCounts.due_soon +
            (can.vessel_manning ? alertCounts.manning_gaps : 0) >
        0;

    return (
        <Main>
            <div className="mb-8 flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <LayoutDashboard className="h-4 w-4 text-primary" />
                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                            Crew Operations
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
                    {can.planning ? (
                        <Button
                            variant="outline"
                            className="rounded-xl glass-card"
                            asChild
                        >
                            <Link href={crewPlanningIndex.url()}>
                                <CalendarRange className="mr-2 h-4 w-4" />
                                Planning
                            </Link>
                        </Button>
                    ) : null}
                    {can.vessel_manning ? (
                        <Button
                            variant="outline"
                            className="rounded-xl glass-card"
                            asChild
                        >
                            <Link href={vesselManningIndex.url()}>
                                <Anchor className="mr-2 h-4 w-4" />
                                Manning
                            </Link>
                        </Button>
                    ) : null}
                    {can.planning ? (
                        <Button
                            variant="outline"
                            className="rounded-xl glass-card"
                            asChild
                        >
                            <Link href={crewOperations.settings.index.url()}>
                                <Settings className="mr-2 h-4 w-4" />
                                Settings
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </div>

            {hasUrgentAlerts ? (
                <Link
                    href={crewOperations.index.url()}
                    className="group mb-8 flex items-center gap-3 rounded-2xl border border-red-500/25 bg-red-500/5 px-5 py-4 transition-all duration-300 hover:border-red-500/40 hover:bg-red-500/10"
                >
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-red-500/20 bg-red-500/10">
                        <AlertTriangle className="h-4.5 w-4.5 text-red-400" />
                    </div>
                    <div className="flex-1">
                        <p className="text-sm font-bold text-red-400">
                            Immediate attention required
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground/75">
                            {[
                                alertCounts.needs_update > 0 &&
                                    `${alertCounts.needs_update} need${alertCounts.needs_update !== 1 ? '' : 's'} update`,
                                alertCounts.overdue_home > 0 &&
                                    `${alertCounts.overdue_home} over home limit (${maxHomeDays}d)`,
                                alertCounts.due_soon > 0 &&
                                    `${alertCounts.due_soon} due within 2 days`,
                                can.vessel_manning &&
                                    alertCounts.manning_gaps > 0 &&
                                    `${alertCounts.manning_gaps} manning gap${alertCounts.manning_gaps !== 1 ? 's' : ''}`,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    </div>
                    <ChevronRight className="h-4 w-4 text-muted-foreground/50 transition-transform group-hover:translate-x-0.5" />
                </Link>
            ) : null}

            <SectionLabel icon={AlertTriangle} label="Alerts" />
            <div
                className={cn(
                    'mb-6 grid gap-4 sm:grid-cols-2',
                    can.vessel_manning ? 'lg:grid-cols-5' : 'lg:grid-cols-4',
                )}
            >
                <MetricCard
                    title="Needs update"
                    value={alertCounts.needs_update.toLocaleString()}
                    hint="Incomplete assignment records"
                    icon={AlertTriangle}
                    iconColor="text-red-400"
                    iconBg="bg-red-500/10 border-red-500/20"
                    accent="border-red-500/20 hover:border-red-500/30"
                    href={crewOperations.index.url()}
                />
                <MetricCard
                    title="Due soon"
                    value={alertCounts.due_soon.toLocaleString()}
                    hint="Key dates within 2 days"
                    icon={Clock}
                    iconColor="text-orange-400"
                    iconBg="bg-orange-500/10 border-orange-500/20"
                    accent="border-orange-500/20 hover:border-orange-500/30"
                    href={crewOperations.index.url()}
                />
                <MetricCard
                    title="Over home limit"
                    value={alertCounts.overdue_home.toLocaleString()}
                    hint={`Exceeds ${maxHomeDays} day limit`}
                    icon={Home}
                    iconColor="text-teal-400"
                    iconBg="bg-teal-500/10 border-teal-500/20"
                    accent="border-teal-500/20 hover:border-teal-500/30"
                    href={crewOperations.index.url()}
                />
                <MetricCard
                    title="Upcoming joins"
                    value={alertCounts.upcoming_planning.toLocaleString()}
                    hint="Planned in next 14 days"
                    icon={CalendarRange}
                    iconColor="text-primary"
                    iconBg="bg-primary/10 border-primary/20"
                    accent="border-primary/20 hover:border-primary/30"
                    href={can.planning ? crewPlanningIndex.url() : undefined}
                />
                {can.vessel_manning ? (
                    <MetricCard
                        title="Manning gaps"
                        value={alertCounts.manning_gaps.toLocaleString()}
                        hint={
                            manningGaps.total_shortfall > 0
                                ? `${manningGaps.total_shortfall} crew short total`
                                : 'Understaffed positions'
                        }
                        icon={Anchor}
                        iconColor="text-amber-400"
                        iconBg="bg-amber-500/10 border-amber-500/20"
                        accent="border-amber-500/20 hover:border-amber-500/30"
                        href={vesselManningIndex.url()}
                    />
                ) : null}
            </div>

            <SectionLabel icon={Ship} label="Deployment status" />
            <div className="mb-8">
                <CrewOperationsDeploymentSummaryCards
                    summary={deploymentSummary}
                />
            </div>

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <Card
                    className={cn(
                        'overflow-hidden glass-card dark:border-white/5 dark:bg-white/2',
                        !can.vessel_manning && 'lg:col-span-2',
                    )}
                >
                    <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <CardTitle className="text-base font-bold tracking-tight">
                                    Deployment trends
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Joins and disembarks over the last 6 months
                                </CardDescription>
                            </div>
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10">
                                <BarChart3 className="h-4 w-4 text-primary" />
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="pt-5">
                        <DeploymentTrendChart data={deploymentTrends} />
                    </CardContent>
                </Card>

                {can.vessel_manning ? (
                    <ManningGapsCard manningGaps={manningGaps} />
                ) : null}
            </div>

            <div className="mb-6 grid gap-6 lg:grid-cols-2">
                <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                    <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                        <CardTitle className="text-base font-bold tracking-tight">
                            Attention required
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Highest-priority crew operations items
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2 pt-4">
                        {attentionItems.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-2 py-10 text-center">
                                <p className="text-sm font-medium text-muted-foreground/50">
                                    No urgent items right now
                                </p>
                            </div>
                        ) : (
                            attentionItems.map((item, index) => (
                                <Link
                                    key={`${item.type}-${item.href}-${index}`}
                                    href={item.href}
                                    className="group flex items-center gap-3 rounded-xl border border-border/80 bg-muted/10 p-3 transition-all hover:border-border hover:bg-muted/30 dark:border-white/5 dark:bg-white/[0.01] dark:hover:border-white/10"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="truncate text-sm font-semibold text-foreground/80 group-hover:text-primary">
                                                {item.title}
                                            </p>
                                            <Badge
                                                variant={
                                                    SEVERITY_BADGE[
                                                        item.severity
                                                    ] ?? 'secondary'
                                                }
                                            >
                                                {item.type.replace(/_/g, ' ')}
                                            </Badge>
                                        </div>
                                        {item.subtitle ? (
                                            <p className="mt-0.5 truncate text-xs text-muted-foreground/60">
                                                {item.subtitle}
                                            </p>
                                        ) : null}
                                        <p className="mt-1 text-[11px] text-muted-foreground/50">
                                            {item.hint}
                                        </p>
                                    </div>
                                    <ArrowUpRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground/45 opacity-0 transition-all group-hover:opacity-100" />
                                </Link>
                            ))
                        )}
                    </CardContent>
                </Card>

                <div className="space-y-6">
                    {can.planning ? (
                        <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                            <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <CardTitle className="text-base font-bold tracking-tight">
                                            Upcoming planning
                                        </CardTitle>
                                        <CardDescription className="text-xs">
                                            Joins scheduled in the next 14 days
                                        </CardDescription>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-8 rounded-lg text-xs"
                                        asChild
                                    >
                                        <Link href={crewPlanningIndex.url()}>
                                            View all
                                        </Link>
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-2 pt-4">
                                {upcomingPlanning.length === 0 ? (
                                    <p className="py-8 text-center text-sm text-muted-foreground/50">
                                        No upcoming assignments
                                    </p>
                                ) : (
                                    upcomingPlanning.map((item) => (
                                        <div
                                            key={item.id}
                                            className="rounded-xl border border-border/80 bg-muted/10 px-3 py-3 dark:border-white/5 dark:bg-white/[0.01]"
                                        >
                                            <p className="text-sm font-semibold text-foreground/80">
                                                {item.employee_name ??
                                                    'Unassigned'}
                                            </p>
                                            <p className="mt-0.5 text-xs text-muted-foreground/60">
                                                {[
                                                    item.vessel_name,
                                                    item.rank_name,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ') ||
                                                    'No vessel / rank'}
                                            </p>
                                            <p className="mt-1 text-[11px] font-medium text-muted-foreground/50">
                                                {formatDisplayDate(
                                                    item.planned_join_date,
                                                )}{' '}
                                                →{' '}
                                                {formatDisplayDate(
                                                    item.planned_leave_date,
                                                )}
                                            </p>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    ) : null}

                    <Card className="overflow-hidden glass-card dark:border-white/5 dark:bg-white/[0.02]">
                        <CardHeader className="border-b border-border/60 pb-4 dark:border-white/5">
                            <CardTitle className="text-base font-bold tracking-tight">
                                Crew pool
                            </CardTitle>
                            <CardDescription className="text-xs">
                                Available employees in configured pool
                                departments
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex items-center justify-between gap-4 pt-5">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-primary/20 bg-primary/10">
                                    <Users className="h-5 w-5 text-primary" />
                                </div>
                                <div>
                                    <p className="text-3xl font-black tabular-nums">
                                        {poolSnapshot.count}
                                    </p>
                                    <p className="text-xs text-muted-foreground/60">
                                        employees in pool
                                    </p>
                                </div>
                            </div>
                            {can.planning ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={crewPlanningIndex.url()}>
                                        Open planning
                                    </Link>
                                </Button>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>
            </div>

            {canViewAudit ? (
                <RecentActivityCard
                    items={recentActivity}
                    description="Latest deployment and planning changes"
                />
            ) : null}
        </Main>
    );
}

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
    accent = 'border-border',
    href,
}: {
    title: string;
    value: string;
    hint: string;
    icon: React.ComponentType<{ className?: string }>;
    iconColor?: string;
    iconBg?: string;
    accent?: string;
    href?: string;
}): ReactElement {
    const content = (
        <Card
            className={cn(
                'group gap-0 overflow-hidden glass-card p-0 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl',
                accent,
                href && 'cursor-pointer',
            )}
        >
            <CardHeader className="relative flex flex-row items-center justify-between space-y-0 px-5 pt-4 pb-1">
                <CardTitle className="text-[10px] font-bold tracking-wider text-muted-foreground/85 uppercase">
                    {title}
                </CardTitle>
                <div
                    className={cn(
                        'flex h-9 w-9 items-center justify-center rounded-xl border',
                        iconBg,
                    )}
                >
                    <Icon className={cn('h-4.5 w-4.5', iconColor)} />
                </div>
            </CardHeader>
            <CardContent className="relative px-5 pt-0 pb-4">
                <div className="text-3xl font-black tracking-tight">
                    {value}
                </div>
                <p className="mt-1.5 text-xs text-muted-foreground/80">
                    {hint}
                </p>
            </CardContent>
        </Card>
    );

    if (href) {
        return <Link href={href}>{content}</Link>;
    }

    return content;
}
