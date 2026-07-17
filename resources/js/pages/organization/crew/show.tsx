import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, FilePenLine, Pencil } from 'lucide-react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { MovementActionMenu } from '@/features/organization/crew/actions/movement-action-menu';
import { CrewMetadataField } from '@/features/organization/crew/components/crew-metadata-field';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import { CrewPhaseProgress } from '@/features/organization/crew/components/crew-phase-progress';
import { CorrectionHistoryCard } from '@/features/organization/crew/corrections/correction-history-card';
import { PendingCorrectionBanner } from '@/features/organization/crew/corrections/pending-correction-banner';
import { RequestCorrectionDialog } from '@/features/organization/crew/corrections/request-correction-dialog';
import { formatDaysInPhase } from '@/features/organization/crew/format-days-in-phase';
import type {
    CorrectionsSummary,
    CrewAssignmentDetail,
    CrewAssignmentFormOptions,
    CrewAssignmentPagePermissions,
} from '@/features/organization/crew/types';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    edit as editAssignment,
    index as crewAssignmentsIndex,
} from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

export default function CrewAssignmentShow({
    assignment,
    corrections,
    recent_activity,
    form_options,
    can,
}: {
    assignment: CrewAssignmentDetail;
    corrections?: CorrectionsSummary;
    recent_activity: RecentActivityItem[];
    form_options?: CrewAssignmentFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    const [isCorrectionDialogOpen, setIsCorrectionDialogOpen] = useState(false);
    const showMovementActions =
        (can.perform_movement || can.cancel) &&
        assignment.available_actions.length > 0;

    return (
        <>
            <Head title={`Assignment ${assignment.assignment_no}`} />
            <Main>
                <DetailsHeader
                    kicker="Current Crew"
                    title={assignment.assignment_no}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <Badge
                                variant={
                                    assignment.status === 'active'
                                        ? 'success'
                                        : assignment.status === 'draft'
                                          ? 'secondary'
                                          : assignment.status === 'cancelled'
                                            ? 'destructive'
                                            : 'outline'
                                }
                            >
                                {assignment.status_label}
                            </Badge>
                            {assignment.current_phase ? (
                                <CrewPhaseBadge
                                    code={assignment.current_phase.code}
                                    label={assignment.current_phase.label}
                                    status={assignment.current_phase.status}
                                />
                            ) : null}
                            {assignment.days_in_phase !== null ? (
                                <span className="text-muted-foreground">
                                    {formatDaysInPhase(
                                        assignment.days_in_phase,
                                    )}
                                </span>
                            ) : null}
                        </span>
                    }
                    backHref={crewAssignmentsIndex.url()}
                    backLabel="Back to Current Crew"
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {showMovementActions ? (
                                <MovementActionMenu
                                    assignmentId={assignment.id}
                                    availableActions={
                                        assignment.available_actions
                                    }
                                    movementContext={
                                        assignment.movement_context
                                    }
                                    formOptions={form_options}
                                />
                            ) : null}
                            {can.update ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-12 rounded-xl px-5"
                                    onClick={() =>
                                        router.visit(
                                            editAssignment.url(assignment.id),
                                        )
                                    }
                                >
                                    <Pencil className="mr-2 h-4 w-4" />
                                    Edit
                                </Button>
                            ) : null}
                            {can.request_correction &&
                            (corrections?.correctable_phases.length ?? 0) >
                                0 ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-12 rounded-xl px-5"
                                    onClick={() =>
                                        setIsCorrectionDialogOpen(true)
                                    }
                                >
                                    <FilePenLine className="mr-2 h-4 w-4" />
                                    Request Correction
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                {corrections ? (
                    <PendingCorrectionBanner corrections={corrections} />
                ) : null}

                {assignment.warnings.length > 0 ? (
                    <div className="mb-6 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
                        <div className="mb-3 flex items-center gap-2 text-amber-700 dark:text-amber-300">
                            <AlertTriangle className="size-4" aria-hidden />
                            <h2 className="text-sm font-semibold">
                                Needs attention
                            </h2>
                        </div>
                        <div className="grid gap-2 md:grid-cols-2">
                            {assignment.warnings.map((warning, idx) => (
                                <div
                                    key={`${warning.code}-${idx}`}
                                    className="rounded-lg border border-amber-500/20 bg-background/60 p-3"
                                >
                                    <p className="text-sm font-medium text-amber-900 dark:text-amber-100">
                                        {warning.label}
                                    </p>
                                    <p className="mt-1 text-xs text-amber-800/80 dark:text-amber-200/80">
                                        {warning.message}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="space-y-6">
                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Movement Progress
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <CrewPhaseProgress
                                    currentPhaseCode={
                                        assignment.current_phase?.code ?? null
                                    }
                                    phaseTimeline={assignment.phase_timeline}
                                />
                            </CardContent>
                        </Card>

                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Phase Timeline
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {assignment.phase_timeline.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No phase timeline recorded yet.
                                    </p>
                                ) : (
                                    <ol className="relative space-y-0 border-l border-border/70 pl-5">
                                        {assignment.phase_timeline.map(
                                            (phase, index) => {
                                                const isCurrent =
                                                    assignment.current_phase
                                                        ?.code ===
                                                    phase.phase_code;

                                                return (
                                                    <li
                                                        key={phase.id}
                                                        className="relative pb-6 last:pb-0"
                                                    >
                                                        <span
                                                            className={cn(
                                                                'absolute top-1.5 -left-[1.4rem] size-2.5 rounded-full border-2 border-background',
                                                                isCurrent
                                                                    ? 'bg-primary'
                                                                    : phase.status ===
                                                                        'completed'
                                                                      ? 'bg-emerald-500'
                                                                      : 'bg-muted-foreground/40',
                                                            )}
                                                            aria-hidden
                                                        />
                                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                                            <div className="space-y-1">
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <CrewPhaseBadge
                                                                        code={
                                                                            phase.phase_code
                                                                        }
                                                                        label={
                                                                            phase.phase_label
                                                                        }
                                                                        status={
                                                                            phase.status
                                                                        }
                                                                    />
                                                                    {isCurrent ? (
                                                                        <Badge variant="outline">
                                                                            Current
                                                                        </Badge>
                                                                    ) : null}
                                                                    {phase.has_pending_correction ? (
                                                                        <Badge variant="warning">
                                                                            Pending
                                                                            Correction
                                                                        </Badge>
                                                                    ) : phase.has_approved_correction ? (
                                                                        <Badge variant="secondary">
                                                                            Corrected
                                                                        </Badge>
                                                                    ) : null}
                                                                </div>
                                                                <p className="text-sm text-muted-foreground">
                                                                    {
                                                                        phase.status_label
                                                                    }
                                                                    {index === 0
                                                                        ? ' · Sequence start'
                                                                        : ''}
                                                                </p>
                                                            </div>
                                                            <div className="text-right text-xs text-muted-foreground">
                                                                <div>
                                                                    Actual:{' '}
                                                                    {formatDisplayDate(
                                                                        phase.actual_start_at,
                                                                    )}
                                                                    {phase.actual_end_at
                                                                        ? ` → ${formatDisplayDate(phase.actual_end_at)}`
                                                                        : ''}
                                                                </div>
                                                                {(phase.planned_start_at ||
                                                                    phase.planned_end_at) && (
                                                                    <div className="mt-1">
                                                                        Planned:{' '}
                                                                        {formatDisplayDate(
                                                                            phase.planned_start_at,
                                                                        )}
                                                                        {phase.planned_end_at
                                                                            ? ` → ${formatDisplayDate(phase.planned_end_at)}`
                                                                            : ''}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </li>
                                                );
                                            },
                                        )}
                                    </ol>
                                )}
                            </CardContent>
                        </Card>

                        {can.view_corrections && corrections ? (
                            <CorrectionHistoryCard corrections={corrections} />
                        ) : null}

                        {(can.perform_movement || can.cancel) &&
                        assignment.available_actions.length === 0 &&
                        assignment.status !== 'completed' &&
                        assignment.status !== 'cancelled' ? (
                            <Card className="border-border/80 dark:border-white/10">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        Movement Actions
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        No available actions for the current
                                        phase.
                                    </p>
                                </CardContent>
                            </Card>
                        ) : null}

                        {can.view_audit ? (
                            recent_activity.length > 0 ? (
                                <RecentActivityCard
                                    items={recent_activity}
                                    description="Latest changes for this crew assignment."
                                />
                            ) : (
                                <Card className="border-border/80 dark:border-white/10">
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base">
                                            Audit History
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-sm text-muted-foreground">
                                            No audit history recorded for this
                                            assignment yet.
                                        </p>
                                    </CardContent>
                                </Card>
                            )
                        ) : null}
                    </div>

                    <div className="space-y-6">
                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Assignment Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="pt-0">
                                <CrewMetadataField
                                    label="Employee"
                                    value={
                                        assignment.employee ? (
                                            <EmployeeProfileLink
                                                employeeId={
                                                    assignment.employee.id
                                                }
                                            >
                                                {assignment.employee.name}
                                            </EmployeeProfileLink>
                                        ) : (
                                            '—'
                                        )
                                    }
                                />
                                <CrewMetadataField
                                    label="Employee No"
                                    value={
                                        assignment.employee?.employee_no ?? '—'
                                    }
                                />
                                <CrewMetadataField
                                    label="Vessel"
                                    value={assignment.vessel?.name ?? '—'}
                                />
                                <CrewMetadataField
                                    label="Rank"
                                    value={assignment.rank?.name ?? '—'}
                                />
                                <CrewMetadataField
                                    label="Client"
                                    value={assignment.client?.name ?? '—'}
                                />
                                <CrewMetadataField
                                    label="Visa Type"
                                    value={
                                        assignment.company_visa_type?.name ??
                                        '—'
                                    }
                                />
                                <CrewMetadataField
                                    label="Source"
                                    value={assignment.source ?? '—'}
                                />
                            </CardContent>
                        </Card>

                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Plan vs Actual
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="pt-0">
                                <CrewMetadataField
                                    label="Planned Join"
                                    value={formatDisplayDate(
                                        assignment.planned_join_at,
                                    )}
                                />
                                <CrewMetadataField
                                    label="Planned Sign-Off"
                                    value={formatDisplayDate(
                                        assignment.planned_signoff_at,
                                    )}
                                />
                                <CrewMetadataField
                                    label="Planned Travel"
                                    value={formatDisplayDate(
                                        assignment.planned_travel_at,
                                    )}
                                />
                                <CrewMetadataField
                                    label="Actual Join"
                                    value={formatDisplayDate(
                                        assignment.actual_join_at,
                                    )}
                                />
                                <CrewMetadataField
                                    label="Actual Disembarkation"
                                    value={formatDisplayDate(
                                        assignment.actual_disembarkation_at,
                                    )}
                                />
                                <CrewMetadataField
                                    label="Started"
                                    value={formatDisplayDate(
                                        assignment.started_at,
                                    )}
                                />
                                <CrewMetadataField
                                    label="Closed"
                                    value={formatDisplayDate(
                                        assignment.closed_at,
                                    )}
                                />
                            </CardContent>
                        </Card>

                        {assignment.remarks ? (
                            <Card className="border-border/80 dark:border-white/10">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">
                                        Remarks
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                        {assignment.remarks}
                                    </p>
                                </CardContent>
                            </Card>
                        ) : null}

                        {assignment.planning_assignment_id ? (
                            <Card className="border-border/80 dark:border-white/10">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">
                                        Planning Link
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground">
                                        Created from crew planning assignment #
                                        {assignment.planning_assignment_id}.
                                    </p>
                                    <Button
                                        asChild
                                        variant="link"
                                        className="mt-1 h-auto px-0"
                                    >
                                        <Link href={crewPlanningIndex.url()}>
                                            Open Crew Planning
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            </Main>

            {corrections ? (
                <RequestCorrectionDialog
                    open={isCorrectionDialogOpen}
                    onOpenChange={setIsCorrectionDialogOpen}
                    assignmentId={assignment.id}
                    correctablePhases={corrections.correctable_phases}
                    formOptions={form_options}
                />
            ) : null}
        </>
    );
}
