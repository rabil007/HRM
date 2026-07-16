import { Head, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { Button } from '@/components/ui/button';
import { MovementActionMenu } from '@/features/organization/crew/actions/movement-action-menu';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import { CrewPhaseProgress } from '@/features/organization/crew/components/crew-phase-progress';
import type {
    CrewAssignmentDetail,
    CrewAssignmentFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import {
    edit as editAssignment,
    index as crewAssignmentsIndex,
} from '@/routes/organization/crew-assignments';

export default function CrewAssignmentShow({
    assignment,
    recent_activity,
    form_options,
    can,
}: {
    assignment: CrewAssignmentDetail;
    recent_activity: RecentActivityItem[];
    form_options?: CrewAssignmentFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    const showMovementActions =
        (can.perform_movement || can.cancel) &&
        assignment.available_actions.length > 0;

    return (
        <>
            <Head title={`Assignment ${assignment.assignment_no}`} />
            <Main>
                <div className="mb-6">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.visit(crewAssignmentsIndex.url())}
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to Crew Assignments
                    </Button>
                </div>

                <PageHeader
                    title={assignment.assignment_no}
                    description={`Status: ${assignment.status_label}`}
                    right={
                        <div className="flex flex-wrap gap-2">
                            {showMovementActions ? (
                                <MovementActionMenu
                                    assignmentId={assignment.id}
                                    availableActions={
                                        assignment.available_actions
                                    }
                                    currentPhase={assignment.current_phase}
                                    formOptions={form_options}
                                    defaultVesselId={assignment.vessel?.id}
                                    defaultRankId={assignment.rank?.id}
                                    defaultClientId={assignment.client?.id}
                                    defaultVisaTypeId={
                                        assignment.company_visa_type?.id
                                    }
                                    defaultPlannedSignoffAt={
                                        assignment.planned_signoff_at
                                    }
                                />
                            ) : null}
                            {can.update ? (
                                <Button
                                    onClick={() =>
                                        router.visit(
                                            editAssignment.url(assignment.id),
                                        )
                                    }
                                >
                                    Edit
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="space-y-6">
                    <div className="rounded-xl glass-card p-6">
                        <h3 className="mb-4 font-semibold">
                            Movement Progress
                        </h3>
                        <CrewPhaseProgress
                            currentPhaseCode={
                                assignment.current_phase?.code ?? null
                            }
                            phaseTimeline={assignment.phase_timeline}
                        />
                    </div>

                    <div className="rounded-xl glass-card p-6">
                        <h3 className="mb-4 font-semibold">Details</h3>
                        <dl className="grid grid-cols-2 gap-4">
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Employee
                                </dt>
                                <dd className="mt-1">
                                    {assignment.employee?.name ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Vessel
                                </dt>
                                <dd className="mt-1">
                                    {assignment.vessel?.name ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Rank
                                </dt>
                                <dd className="mt-1">
                                    {assignment.rank?.name ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Client
                                </dt>
                                <dd className="mt-1">
                                    {assignment.client?.name ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Current Phase
                                </dt>
                                <dd className="mt-1">
                                    {assignment.current_phase ? (
                                        <CrewPhaseBadge
                                            code={assignment.current_phase.code}
                                            label={
                                                assignment.current_phase.label
                                            }
                                            status={
                                                assignment.current_phase.status
                                            }
                                        />
                                    ) : (
                                        'N/A'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Planned Join
                                </dt>
                                <dd className="mt-1">
                                    {assignment.planned_join_at ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Planned Sign-Off
                                </dt>
                                <dd className="mt-1">
                                    {assignment.planned_signoff_at ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Actual Join
                                </dt>
                                <dd className="mt-1">
                                    {assignment.actual_join_at ?? 'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Actual Disembarkation
                                </dt>
                                <dd className="mt-1">
                                    {assignment.actual_disembarkation_at ??
                                        'N/A'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm font-medium text-muted-foreground">
                                    Days in Phase
                                </dt>
                                <dd className="mt-1">
                                    {assignment.days_in_phase ?? 'N/A'}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div className="rounded-xl glass-card p-6">
                        <h3 className="mb-4 font-semibold">Phase Timeline</h3>
                        {assignment.phase_timeline.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No phase timeline recorded yet.
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {assignment.phase_timeline.map((phase) => (
                                    <div
                                        key={phase.id}
                                        className="flex items-center gap-4 rounded-lg border p-3"
                                    >
                                        <CrewPhaseBadge
                                            code={phase.phase_code}
                                            label={phase.phase_label}
                                            status={phase.status}
                                        />
                                        <div className="flex-1 text-sm">
                                            <div className="font-medium">
                                                {phase.status_label}
                                            </div>
                                            {phase.actual_start_at ? (
                                                <div className="text-muted-foreground">
                                                    {phase.actual_start_at}
                                                    {phase.actual_end_at
                                                        ? ` - ${phase.actual_end_at}`
                                                        : null}
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {(can.perform_movement || can.cancel) &&
                    assignment.available_actions.length === 0 &&
                    assignment.status !== 'completed' &&
                    assignment.status !== 'cancelled' ? (
                        <div className="rounded-xl glass-card p-6">
                            <h3 className="mb-2 font-semibold">
                                Movement Actions
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                No available actions for the current phase.
                            </p>
                        </div>
                    ) : null}

                    {assignment.warnings.length > 0 ? (
                        <div className="rounded-xl glass-card border-amber-500 p-6">
                            <h3 className="mb-4 font-semibold text-amber-600">
                                Warnings
                            </h3>
                            <div className="space-y-2">
                                {assignment.warnings.map((warning, idx) => (
                                    <div
                                        key={`${warning.code}-${idx}`}
                                        className="rounded-lg border border-amber-500/20 bg-amber-50 p-3 dark:bg-amber-950/20"
                                    >
                                        <div className="font-medium text-amber-900 dark:text-amber-100">
                                            {warning.label}
                                        </div>
                                        <div className="text-sm text-amber-700 dark:text-amber-300">
                                            {warning.message}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : null}

                    {can.view_audit ? (
                        recent_activity.length > 0 ? (
                            <RecentActivityCard
                                items={recent_activity}
                                description="Latest changes for this crew assignment."
                            />
                        ) : (
                            <div className="rounded-xl glass-card p-6">
                                <h3 className="mb-2 font-semibold">
                                    Audit History
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    No audit history recorded for this
                                    assignment yet.
                                </p>
                            </div>
                        )
                    ) : null}
                </div>
            </Main>
        </>
    );
}
