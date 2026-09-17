import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Clock3,
    FileCheck2,
    Ship,
    UsersRound,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { MovementActionDialog } from '@/features/organization/crew/actions/movement-action-dialog';
import { MovementActionMenu } from '@/features/organization/crew/actions/movement-action-menu';
import { CrewMobilisationReadinessBadge } from '@/features/organization/crew/components/crew-mobilisation-readiness-badge';
import { CrewOperationalStatePanel } from '@/features/organization/crew/components/crew-operational-state-panel';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import { CrewTourProgressDisplay } from '@/features/organization/crew/components/crew-tour-progress-display';
import { formatDaysInPhase } from '@/features/organization/crew/format-days-in-phase';
import { crewPhaseDescription } from '@/features/organization/crew/lib/crew-phase-descriptions';
import {
    legacyPhaseContextLabel,
    NORMAL_PHASE_PROGRESS_STEPS,
    normalProgressStepState,
} from '@/features/organization/crew/lib/crew-phase-visibility';
import {
    crewQuickDetailModel,
    relativePlanDate,
} from '@/features/organization/crew/lib/quick-detail';
import type {
    QuickDetailIssue,
    QuickDetailPermissions,
} from '@/features/organization/crew/lib/quick-detail';
import { reliefActionHref } from '@/features/organization/crew/lib/relief-action';
import type {
    CrewAssignmentFormOptions,
    CrewAssignmentListItem,
    CrewMovementAction,
} from '@/features/organization/crew/types';
import { CREW_MOVEMENT_ACTION_LABELS } from '@/features/organization/crew/types';
import { buildEmployeeShowUrl } from '@/features/organization/employees/build-employee-show-url';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as showAssignment } from '@/routes/organization/crew-assignments';

export function CrewAssignmentQuickDetailSheet({
    assignment,
    open,
    onOpenChange,
    can,
    formOptions,
    position,
    total,
    onPrevious,
    onNext,
}: {
    assignment: CrewAssignmentListItem | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    can: QuickDetailPermissions;
    formOptions?: CrewAssignmentFormOptions;
    position: number;
    total: number;
    onPrevious?: () => void;
    onNext?: () => void;
}) {
    const returnFocus = useRef<HTMLElement | null>(null);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                className="w-full gap-0 overflow-hidden p-0 sm:max-w-[440px] [&>button]:top-4"
                onOpenAutoFocus={() => {
                    returnFocus.current =
                        document.activeElement instanceof HTMLElement
                            ? document.activeElement
                            : null;
                }}
                onCloseAutoFocus={(event) => {
                    if (returnFocus.current?.isConnected) {
                        event.preventDefault();
                        returnFocus.current.focus();
                    }
                }}
            >
                {assignment ? (
                    <QuickDetailContent
                        key={assignment.id}
                        assignment={assignment}
                        can={can}
                        formOptions={formOptions}
                        position={position}
                        total={total}
                        onPrevious={onPrevious}
                        onNext={onNext}
                    />
                ) : (
                    <SheetHeader>
                        <SheetTitle>Crew quick view</SheetTitle>
                        <SheetDescription>
                            Select an assignment to review.
                        </SheetDescription>
                    </SheetHeader>
                )}
            </SheetContent>
        </Sheet>
    );
}

function QuickDetailContent({
    assignment,
    can,
    formOptions,
    position,
    total,
    onPrevious,
    onNext,
}: {
    assignment: CrewAssignmentListItem;
    can: QuickDetailPermissions;
    formOptions?: CrewAssignmentFormOptions;
    position: number;
    total: number;
    onPrevious?: () => void;
    onNext?: () => void;
}) {
    const [selectedMovement, setSelectedMovement] = useState<{
        assignmentId: number;
        action: CrewMovementAction;
    } | null>(null);
    const [failedImage, setFailedImage] = useState<string | null>(null);
    const selectedAction =
        selectedMovement?.assignmentId === assignment.id
            ? selectedMovement.action
            : null;
    const model = crewQuickDetailModel(assignment, can);
    const context = assignment.movement_context;
    const phase = assignment.current_phase;
    const legacyContext = legacyPhaseContextLabel(phase?.code ?? null);
    const phaseDescription = crewPhaseDescription(phase?.code);
    const isOnVessel = phase?.code === 'p4' && !model.finished;
    const documentsHref =
        can.view_documents && assignment.employee
            ? (model.readiness?.documents_href ??
              `${buildEmployeeShowUrl(assignment.employee.id)}#documents`)
            : null;
    const reliefHref =
        can.view_planning && isOnVessel ? reliefActionHref(assignment) : null;
    const primaryHref =
        model.needsDocumentReview && documentsHref ? documentsHref : null;
    const recommendedMovement =
        assignment.recommended_action?.type === 'movement' &&
        assignment.recommended_action.action
            ? (assignment.recommended_action.action as CrewMovementAction)
            : null;
    const primaryLabel = primaryHref
        ? 'Review documents'
        : assignment.recommended_action?.type === 'relief' &&
            assignment.recommended_action.label
          ? assignment.recommended_action.label
          : recommendedMovement
            ? CREW_MOVEMENT_ACTION_LABELS[recommendedMovement]
            : model.movement
              ? CREW_MOVEMENT_ACTION_LABELS[model.movement]
              : null;
    const primaryMovement = primaryHref
        ? null
        : (recommendedMovement ?? model.movement);
    const actualDates = actualMovementDates(assignment, phase);
    const hasCriticalIssue = model.issues.some(
        (issue) => issue.severity === 'critical',
    );
    const overdue =
        model.daysUntilMilestone !== null && model.daysUntilMilestone < 0;

    return (
        <>
            <div className="flex shrink-0 items-center justify-between border-b border-border/60 bg-muted/25 py-2 pr-12 pl-4">
                <span className="text-xs font-semibold text-muted-foreground">
                    Crew quick view
                </span>
                <div className="flex items-center gap-1">
                    <span
                        className="mr-1 text-xs text-muted-foreground tabular-nums"
                        aria-live="polite"
                    >
                        {position} / {total} on page
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        disabled={!onPrevious}
                        onClick={onPrevious}
                        aria-label="Previous crew assignment"
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        disabled={!onNext}
                        onClick={onNext}
                        aria-label="Next crew assignment"
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                </div>
            </div>
            <SheetHeader className="shrink-0 gap-3 border-b border-border/60 px-4 py-4">
                <div className="flex items-start gap-3">
                    <div
                        onErrorCapture={() =>
                            setFailedImage(assignment.employee?.image ?? null)
                        }
                    >
                        <EmployeeAvatar
                            name={assignment.employee?.name ?? 'Crew'}
                            image={
                                failedImage === assignment.employee?.image
                                    ? null
                                    : assignment.employee?.image
                            }
                            size="sm"
                        />
                    </div>
                    <div className="min-w-0 flex-1">
                        <SheetTitle className="text-base leading-snug wrap-break-word">
                            {assignment.employee?.name ?? 'Unassigned crew'}
                        </SheetTitle>
                        <SheetDescription className="mt-0.5 text-xs">
                            {[
                                assignment.employee?.employee_no,
                                assignment.rank?.name,
                            ]
                                .filter(Boolean)
                                .join(' · ') || 'Rank not assigned'}
                        </SheetDescription>
                        <p className="mt-1 text-[11px] text-muted-foreground">
                            <span className="font-mono">
                                {assignment.assignment_no}
                            </span>
                            <span className="mx-1.5" aria-hidden>
                                ·
                            </span>
                            {assignment.status_label}
                        </p>
                    </div>
                </div>
                <div className="flex items-start gap-2 text-xs">
                    <Ship
                        className="mt-0.5 size-3.5 shrink-0 text-primary"
                        aria-hidden
                    />
                    <div className="min-w-0 leading-relaxed">
                        <span className="font-semibold wrap-break-word">
                            {assignment.vessel?.name ?? 'Vessel not assigned'}
                        </span>
                        {assignment.client ? (
                            <span className="text-muted-foreground">
                                {' '}
                                · {assignment.client.name}
                            </span>
                        ) : null}
                    </div>
                </div>
            </SheetHeader>

            <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                <div className="grid grid-cols-2 divide-x divide-border/60 border-b border-border/60 bg-muted/15">
                    <div className="flex flex-col gap-2 px-4 py-3">
                        <Label icon={<Clock3 />}>Current phase</Label>
                        {phase ? (
                            <CrewPhaseBadge
                                code={phase.code}
                                label={phase.label}
                                status={phase.status}
                            />
                        ) : (
                            <span className="text-sm">Not recorded</span>
                        )}
                        {phaseDescription ? (
                            <p className="text-xs text-muted-foreground">
                                {phaseDescription}
                            </p>
                        ) : null}
                        {legacyContext ? (
                            <p className="text-xs font-medium text-amber-700 dark:text-amber-300">
                                {legacyContext}
                            </p>
                        ) : null}
                        <p className="text-xs text-muted-foreground">
                            {formatDaysInPhase(assignment.days_in_phase)}
                        </p>
                    </div>
                    <div className="flex flex-col gap-1.5 px-4 py-3">
                        <Label icon={<CalendarClock />}>
                            {model.milestone?.label ?? 'Next step'}
                        </Label>
                        <p className="text-sm font-semibold tabular-nums">
                            {model.milestone
                                ? formatDisplayDate(model.milestone.date)
                                : model.finished
                                  ? 'Review history'
                                  : phase?.code === 'p5'
                                    ? 'Travel or redeploy'
                                    : phase?.code === 'p6'
                                      ? 'Close or redeploy'
                                      : 'Review assignment'}
                        </p>
                        {model.milestone ? (
                            <span
                                className={cn(
                                    'text-xs font-medium',
                                    overdue
                                        ? 'text-destructive'
                                        : model.daysUntilMilestone === 0
                                          ? 'text-amber-700 dark:text-amber-300'
                                          : 'text-muted-foreground',
                                )}
                            >
                                {relativePlanDate(model.daysUntilMilestone)}
                            </span>
                        ) : null}
                    </div>
                </div>

                <CrewPhasePath
                    currentPhaseCode={phase?.code ?? null}
                    legacyContext={legacyContext}
                />

                <div className="border-b border-border/60 px-4 py-4">
                    <CrewOperationalStatePanel
                        assignment={assignment}
                        recommended={assignment.recommended_action}
                        permissions={{
                            perform_movement: can.perform_movement,
                            cancel: can.cancel,
                        }}
                        showLastChange
                    />
                </div>

                <div className="flex flex-col gap-5 px-4 py-4">
                    <section
                        className={cn(
                            'rounded-lg border-l-[3px] bg-muted/35 px-3 py-2.5',
                            hasCriticalIssue
                                ? 'border-l-destructive'
                                : model.issues.length ||
                                    model.needsRelief ||
                                    overdue
                                  ? 'border-l-amber-500'
                                  : 'border-l-primary',
                        )}
                    >
                        <h3 className="text-xs font-semibold">
                            {model.finished
                                ? 'Assignment history'
                                : model.issues.length
                                  ? 'Review before the next movement'
                                  : model.needsRelief
                                    ? 'Relief needs a review'
                                    : 'Operations brief'}
                        </h3>
                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                            {model.focus}
                        </p>
                        {model.issues.length > 0 ? (
                            <div className="mt-3 space-y-2">
                                {model.issues.slice(0, 2).map((issue) => (
                                    <Issue key={issue.key} issue={issue} />
                                ))}
                                {model.issues.length > 2 ? (
                                    <details className="group">
                                        <summary className="flex cursor-pointer list-none items-center gap-1 py-1 text-xs font-medium text-primary focus-visible:outline-2 focus-visible:outline-ring">
                                            <ChevronDown className="size-3.5 transition-transform group-open:rotate-180" />
                                            {model.issues.length - 2} more
                                            alerts
                                        </summary>
                                        <div className="mt-2 space-y-2">
                                            {model.issues
                                                .slice(2)
                                                .map((issue) => (
                                                    <Issue
                                                        key={issue.key}
                                                        issue={issue}
                                                    />
                                                ))}
                                        </div>
                                    </details>
                                ) : null}
                            </div>
                        ) : null}
                    </section>

                    {isOnVessel ? (
                        <section className="space-y-2.5">
                            <SectionTitle icon={<UsersRound />}>
                                Tour & relief
                            </SectionTitle>
                            <CrewTourProgressDisplay
                                progress={assignment}
                                plannedSignoffAt={assignment.planned_signoff_at}
                            />
                            <div className="rounded-lg border border-border/70 p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-xs font-medium">
                                        Relief crew
                                    </span>
                                    <Badge
                                        variant={
                                            assignment.relief_risk ===
                                            'critical'
                                                ? 'destructive'
                                                : assignment.relief_risk ===
                                                    'warning'
                                                  ? 'warning'
                                                  : 'outline'
                                        }
                                        className="text-[10px]"
                                    >
                                        {assignment.relief_status_label ??
                                            'Not assessed'}
                                    </Badge>
                                </div>
                                <p className="mt-1.5 text-sm font-semibold wrap-break-word">
                                    {assignment.relief_employee?.name ??
                                        'No relief assigned'}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {assignment.relief_employee?.employee_no
                                        ? `${assignment.relief_employee.employee_no} · `
                                        : ''}
                                    {assignment.relief_phase_label ??
                                        'No relief movement recorded'}
                                </p>
                                <dl className="mt-2">
                                    <DataRow
                                        label="Relief planned join"
                                        value={formatDisplayDate(
                                            assignment.relief_planned_join_date,
                                        )}
                                    />
                                </dl>
                                {model.reliefGapDays !== null ? (
                                    <p
                                        className={cn(
                                            'mt-1 text-xs leading-relaxed',
                                            model.reliefGapDays > 0
                                                ? 'text-destructive'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {model.reliefGapDays > 0
                                            ? `Relief joins ${model.reliefGapDays}d after planned sign-off. Review coverage.`
                                            : model.reliefGapDays === 0
                                              ? 'Relief join and sign-off are planned for the same day.'
                                              : `Relief joins ${Math.abs(model.reliefGapDays)}d before planned sign-off.`}
                                    </p>
                                ) : null}
                                {assignment.relief_risk &&
                                assignment.relief_risk !== 'none' ? (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {assignment.relief_risk_label}
                                    </p>
                                ) : null}
                                {reliefHref ? (
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        className="mt-3 w-full justify-between text-xs"
                                    >
                                        <Link href={reliefHref}>
                                            {assignment.relief_action_label ??
                                                'Review relief'}
                                            <ArrowRight className="size-3.5" />
                                        </Link>
                                    </Button>
                                ) : null}
                            </div>
                        </section>
                    ) : null}

                    {model.readiness ? (
                        <section className="space-y-2.5">
                            <SectionTitle icon={<FileCheck2 />}>
                                Document readiness
                            </SectionTitle>
                            <div className="flex items-center justify-between gap-3">
                                <CrewMobilisationReadinessBadge
                                    readiness={model.readiness}
                                    compact
                                />
                                {model.readiness.checks_total > 0 ? (
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {model.readiness.checks_clear} /{' '}
                                        {model.readiness.checks_total} clear
                                    </span>
                                ) : null}
                            </div>
                            {model.readiness.checks_total > 0 ? (
                                <progress
                                    className="h-1.5 w-full overflow-hidden rounded-full [&::-moz-progress-bar]:bg-primary [&::-webkit-progress-bar]:bg-muted [&::-webkit-progress-value]:bg-primary"
                                    value={model.readiness.checks_clear}
                                    max={model.readiness.checks_total}
                                    aria-label="Required document checks clear"
                                />
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    No required checks are configured. Readiness
                                    has not been verified.
                                </p>
                            )}
                            {model.readiness.checks_total -
                                model.readiness.checks_clear >
                            (model.readiness.problems?.length ?? 0) ? (
                                <p className="text-xs text-muted-foreground">
                                    Showing the leading document issues. Open
                                    documents for the full review.
                                </p>
                            ) : null}
                            {documentsHref && primaryHref !== documentsHref ? (
                                <Link
                                    href={documentsHref}
                                    className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                >
                                    Open documents
                                    <ArrowRight className="size-3" />
                                </Link>
                            ) : null}
                        </section>
                    ) : null}

                    {phase?.code === 'p2b' ? (
                        <section className="space-y-2">
                            <SectionTitle>Current training</SectionTitle>
                            <dl className="divide-y divide-border/50">
                                <DataRow
                                    label="Course"
                                    value={
                                        context.training_course ??
                                        'Not recorded'
                                    }
                                />
                                <DataRow
                                    label="Provider"
                                    value={
                                        context.training_provider ??
                                        'Not recorded'
                                    }
                                />
                                <DataRow
                                    label="Started"
                                    value={formatDisplayDate(
                                        context.training_started_at,
                                    )}
                                />
                                <DataRow
                                    label="Expected completion"
                                    value={formatDisplayDate(
                                        context.training_expected_completion_at,
                                    )}
                                />
                            </dl>
                        </section>
                    ) : null}

                    <section className="space-y-2">
                        <SectionTitle icon={<CalendarClock />}>
                            Key dates
                        </SectionTitle>
                        {actualDates.length ? (
                            <dl className="divide-y divide-border/50">
                                {actualDates.map((date) => (
                                    <DataRow
                                        key={`${date.label}-${date.value}`}
                                        label={date.label}
                                        value={formatDisplayDate(date.value)}
                                    />
                                ))}
                            </dl>
                        ) : (
                            <p className="rounded-md border border-dashed border-border/70 px-3 py-2 text-xs text-muted-foreground">
                                No confirmed movement dates recorded yet.
                            </p>
                        )}
                        <p className="text-[11px] text-muted-foreground">
                            Confirmed movement dates in {model.timezone}.
                        </p>
                    </section>
                </div>
            </div>

            <div className="shrink-0 space-y-2 border-t border-border/70 bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                <div className="flex items-center gap-2">
                    {primaryHref ? (
                        <Button asChild className="min-w-0 flex-1 text-xs">
                            <Link href={primaryHref}>
                                {primaryLabel}
                                <ArrowRight className="size-3.5" />
                            </Link>
                        </Button>
                    ) : primaryMovement ? (
                        <Button
                            type="button"
                            className="min-w-0 flex-1 text-xs"
                            onClick={() =>
                                setSelectedMovement({
                                    assignmentId: assignment.id,
                                    action: primaryMovement,
                                })
                            }
                        >
                            {primaryLabel}
                            <ArrowRight className="size-3.5" />
                        </Button>
                    ) : null}
                    {model.availableActions.length > 0 ? (
                        <MovementActionMenu
                            assignmentId={assignment.id}
                            availableActions={model.availableActions}
                            movementContext={context}
                            formOptions={formOptions}
                            excludeActions={
                                primaryMovement ? [primaryMovement] : []
                            }
                            triggerLabel={
                                primaryLabel
                                    ? 'More actions'
                                    : 'Record movement'
                            }
                        />
                    ) : null}
                </div>
                <Link
                    href={showAssignment.url(assignment.id)}
                    className="flex min-h-8 items-center justify-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                >
                    Open full assignment
                    <ArrowRight className="size-3" />
                </Link>
            </div>
            <MovementActionDialog
                key={assignment.id}
                open={selectedAction !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) {
                        setSelectedMovement(null);
                    }
                }}
                action={selectedAction}
                assignmentId={assignment.id}
                movementContext={context}
                formOptions={formOptions}
            />
        </>
    );
}

const CREW_PHASE_PATH = NORMAL_PHASE_PROGRESS_STEPS;

function CrewPhasePath({
    currentPhaseCode,
    legacyContext,
}: {
    currentPhaseCode: string | null;
    legacyContext: string | null;
}) {
    const currentStep = CREW_PHASE_PATH.find(
        (step) =>
            normalProgressStepState(step, currentPhaseCode, []) === 'current',
    );

    return (
        <section
            className="border-b border-border/60 bg-muted/10 px-4 py-3"
            aria-label="Crew movement phase path"
        >
            {legacyContext ? (
                <p className="mb-2 text-[11px] font-medium text-amber-700 dark:text-amber-300">
                    {legacyContext}
                </p>
            ) : null}
            <div className="flex items-center justify-between gap-3">
                <span className="text-[11px] font-semibold text-muted-foreground">
                    Phase path
                </span>
                <span className="truncate text-[11px] font-medium">
                    {currentStep?.label ?? 'No phase recorded'}
                </span>
            </div>
            <ol
                className="mt-2 flex items-center"
                aria-label="Crew movement phases"
            >
                {CREW_PHASE_PATH.map((step, index) => {
                    const state = normalProgressStepState(
                        step,
                        currentPhaseCode,
                        [],
                    );
                    const isCurrent = !legacyContext && state === 'current';
                    const isComplete = state === 'completed';

                    return (
                        <li
                            key={step.key}
                            className="flex min-w-0 flex-1 items-center last:flex-none"
                        >
                            <span
                                className={cn(
                                    'flex size-6 shrink-0 items-center justify-center rounded-full border text-[10px] font-bold tabular-nums',
                                    isCurrent &&
                                        'border-primary bg-primary text-primary-foreground ring-2 ring-primary/20',
                                    isComplete &&
                                        'border-emerald-500 bg-emerald-500 text-white',
                                    !isCurrent &&
                                        !isComplete &&
                                        'border-border bg-background text-muted-foreground',
                                )}
                                title={step.label}
                                aria-current={isCurrent ? 'step' : undefined}
                            >
                                {step.code}
                            </span>
                            {index < CREW_PHASE_PATH.length - 1 ? (
                                <span
                                    className={cn(
                                        'mx-1 h-px min-w-1 flex-1',
                                        isComplete
                                            ? 'bg-emerald-500'
                                            : 'bg-border',
                                    )}
                                    aria-hidden
                                />
                            ) : null}
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}

function actualMovementDates(
    assignment: CrewAssignmentListItem,
    phase: CrewAssignmentListItem['current_phase'],
): Array<{ label: string; value: string }> {
    const dates = [
        {
            label: 'Joined vessel',
            value:
                assignment.actual_join_at ??
                assignment.movement_context.actual_join_at,
        },
        {
            label: 'Disembarked',
            value: assignment.movement_context.actual_disembarkation_at,
        },
    ].filter((date): date is { label: string; value: string } =>
        Boolean(date.value),
    );
    const phaseStartedAt =
        phase?.started_at ??
        assignment.movement_context.current_phase_started_at;

    if (
        phaseStartedAt &&
        !dates.some(
            (date) => date.value.slice(0, 10) === phaseStartedAt.slice(0, 10),
        )
    ) {
        dates.push({
            label: `Entered ${phase?.label ?? 'current phase'}`,
            value: phaseStartedAt,
        });
    }

    return dates;
}

function Label({ icon, children }: { icon?: ReactNode; children: ReactNode }) {
    return (
        <span className="flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground [&_svg]:size-3.5">
            {icon}
            {children}
        </span>
    );
}

function SectionTitle({
    icon,
    children,
}: {
    icon?: ReactNode;
    children: ReactNode;
}) {
    return (
        <h3 className="flex items-center gap-1.5 text-xs font-semibold [&_svg]:size-3.5 [&_svg]:text-muted-foreground">
            {icon}
            {children}
        </h3>
    );
}

function DataRow({
    label,
    value,
    kind,
}: {
    label: string;
    value: string;
    kind?: 'Plan' | 'Actual';
}) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-2 text-xs">
            <dt className="shrink-0 text-muted-foreground">
                {label}
                {kind ? (
                    <span className="ml-1.5 text-[10px] opacity-75">
                        · {kind}
                    </span>
                ) : null}
            </dt>
            <dd className="min-w-0 text-right font-medium wrap-break-word tabular-nums">
                {value}
            </dd>
        </div>
    );
}

function Issue({ issue }: { issue: QuickDetailIssue }) {
    return (
        <div className="flex items-start gap-2">
            <CircleAlert
                className={cn(
                    'mt-0.5 size-3.5 shrink-0',
                    issue.severity === 'critical'
                        ? 'text-destructive'
                        : 'text-amber-700 dark:text-amber-300',
                )}
                aria-hidden
            />
            <div className="min-w-0">
                <p className="text-xs font-medium">{issue.label}</p>
                {issue.message && issue.message !== issue.label ? (
                    <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                        {issue.message}
                    </p>
                ) : null}
                <span className="text-[10px] text-muted-foreground">
                    {issue.source} ·{' '}
                    {issue.severity === 'critical'
                        ? 'Critical'
                        : issue.severity === 'warning'
                          ? 'Attention'
                          : 'Information'}
                </span>
            </div>
        </div>
    );
}
