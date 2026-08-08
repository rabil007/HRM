import { Anchor, Navigation, Home, AlertTriangle, Clock } from 'lucide-react';
import { index as crewAssignmentsIndex } from '@/routes/organization/crew-assignments';
import { DashboardMetricCard } from '../components/dashboard-metric-card';
import { DashboardSection } from '../components/dashboard-section';
import type { CrewDashboardSummary } from '../dashboard-types';

type CrewSectionProps = {
    summary?: CrewDashboardSummary;
};

export function CrewSection({ summary }: CrewSectionProps) {
    if (!summary) {
        return null;
    }

    const inHomeValue = summary.in_home ?? summary.at_home;
    const updatesRequired =
        summary.movement_updates_required ?? summary.needs_update;

    return (
        <DashboardSection
            title="Crew Operations Overview"
            description="Vessel deployment, home standbys, and crew movement tracking"
            icon={Anchor}
            actionLabel="Crew Assignments"
            actionHref={crewAssignmentsIndex.url()}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardMetricCard
                    title="On Vessel"
                    value={summary.on_vessel}
                    subtitle={`Out of ${summary.total} total crew members`}
                    icon={Navigation}
                    iconColor="text-cyan-500"
                    href={crewAssignmentsIndex.url({
                        query: { status: 'on_vessel' },
                    })}
                />

                <DashboardMetricCard
                    title="In Home / Standby"
                    value={inHomeValue}
                    subtitle={`${summary.ready_to_join} ready to join vessel`}
                    icon={Home}
                    iconColor="text-blue-500"
                    href={crewAssignmentsIndex.url({
                        query: { status: 'in_home' },
                    })}
                />

                <DashboardMetricCard
                    title="Movement Updates Needed"
                    value={updatesRequired}
                    subtitle="Status or location update required"
                    icon={AlertTriangle}
                    iconColor="text-rose-500"
                    badgeText={updatesRequired > 0 ? 'Critical' : 'Updated'}
                    badgeVariant={updatesRequired > 0 ? 'danger' : 'success'}
                    href={crewAssignmentsIndex.url({
                        query: { filter: 'needs_update' },
                    })}
                />

                <DashboardMetricCard
                    title="Sign-offs Due"
                    value={summary.planned_signoffs_due}
                    subtitle={`${summary.overdue_at_home} overdue at home`}
                    icon={Clock}
                    iconColor="text-amber-500"
                    badgeVariant={
                        summary.planned_signoffs_due > 0 ? 'warning' : 'default'
                    }
                    href={crewAssignmentsIndex.url({
                        query: { filter: 'signoffs_due' },
                    })}
                />
            </div>
        </DashboardSection>
    );
}
