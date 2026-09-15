import { Link, usePoll } from '@inertiajs/react';
import {
    CalendarDays,
    CalendarRange,
    LayoutDashboard,
    Users,
} from 'lucide-react';
import type { ReactElement } from 'react';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { ActionRequiredCard } from '@/features/organization/crew-operations/components/action-required-card';
import { CoverageHorizonCard } from '@/features/organization/crew-operations/components/coverage-horizon-card';
import { DailyPulse } from '@/features/organization/crew-operations/components/daily-pulse';
import { ManningReliefRisksCard } from '@/features/organization/crew-operations/components/manning-relief-risks-card';
import { NextSevenDaysCard } from '@/features/organization/crew-operations/components/next-seven-days-card';
import { OperationalBriefing } from '@/features/organization/crew-operations/components/operational-briefing';
import type { CrewOperationsDashboardProps } from '@/features/organization/crew-operations/types';
import { formatDisplayDate } from '@/lib/format-date';
import { index as crewAssignmentsIndex } from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

export type { CrewOperationsDashboardProps } from '@/features/organization/crew-operations/types';

export function CrewOperationsDashboardContent({
    today,
    company_timezone: companyTimezone,
    daily_pulse: dailyPulse,
    action_required: actionRequired,
    next_seven_days: nextSevenDays,
    manning_relief_risks: manningReliefRisks,
    projected_manning: projectedManning,
    can,
}: CrewOperationsDashboardProps): ReactElement {
    usePoll(60_000, {
        only: [
            'daily_pulse',
            'action_required',
            'next_seven_days',
            'manning_relief_risks',
            'projected_manning',
        ],
    });

    return (
        <Main className="flex flex-1 flex-col gap-6">
            <div className="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <LayoutDashboard className="h-4 w-4 text-primary" />
                        <span className="text-[10px] font-bold tracking-[0.2em] text-muted-foreground/60 uppercase">
                            Crew Operations
                        </span>
                    </div>
                    <h1 className="bg-linear-to-br from-foreground to-foreground/50 bg-clip-text text-4xl font-extrabold tracking-tight text-transparent">
                        Crew operations
                    </h1>
                    <p className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground/60">
                        <CalendarDays className="h-3.5 w-3.5" />
                        Today, {formatDisplayDate(today)} · {companyTimezone}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    {can.assignments ? (
                        <Button
                            variant="outline"
                            className="rounded-xl glass-card"
                            asChild
                        >
                            <Link href={crewAssignmentsIndex.url()}>
                                <Users className="mr-2 h-4 w-4" />
                                Current Crew
                            </Link>
                        </Button>
                    ) : null}
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
                </div>
            </div>

            <section className="space-y-3" aria-labelledby="operations-status">
                <div>
                    <h2
                        id="operations-status"
                        className="text-sm font-semibold text-foreground"
                    >
                        Operating status
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Crew on board, scheduled movements, and coverage health.
                    </p>
                </div>
                <DailyPulse
                    pulse={dailyPulse}
                    canViewProjected={can.vessel_manning}
                />
                <OperationalBriefing
                    actionItems={actionRequired}
                    movementDays={nextSevenDays}
                    reliefRisks={manningReliefRisks}
                    projectedManning={
                        can.vessel_manning ? projectedManning : null
                    }
                />
            </section>

            <section
                className="space-y-3"
                aria-labelledby="operations-attention"
            >
                <div>
                    <h2
                        id="operations-attention"
                        className="text-sm font-semibold text-foreground"
                    >
                        Needs attention
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Resolve urgent crew, relief, and vessel coverage issues.
                    </p>
                </div>
                <div className="grid gap-6 xl:grid-cols-2">
                    <ActionRequiredCard items={actionRequired} />
                    <ManningReliefRisksCard
                        risks={manningReliefRisks}
                        canViewPlanning={can.planning}
                    />
                </div>
            </section>

            <section
                className="space-y-3"
                aria-labelledby="operations-planning"
            >
                <div>
                    <h2
                        id="operations-planning"
                        className="text-sm font-semibold text-foreground"
                    >
                        Plan ahead
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Confirm this week&apos;s movements and protect future
                        coverage.
                    </p>
                </div>
                <div className="grid gap-6 xl:grid-cols-2">
                    <NextSevenDaysCard
                        days={nextSevenDays}
                        canViewPlanning={can.planning}
                    />
                    {can.vessel_manning && projectedManning ? (
                        <CoverageHorizonCard
                            projected={projectedManning}
                            canViewManning={can.vessel_manning}
                        />
                    ) : null}
                </div>
            </section>
        </Main>
    );
}
