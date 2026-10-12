import { useDraggable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { router } from '@inertiajs/react';
import { AlertTriangle, Calendar, GripVertical } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { KeyboardEvent, MouseEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    candidateIsStalled,
    candidateNeedsAttention,
} from '../lib/candidate-attention';
import { shouldNavigateKanbanCardClick } from '../lib/kanban-card-interaction';
import { STAGE_COLORS } from '../lib/kanban-stage-meta';
import type { CandidateIndexRow, CandidateStage } from '../types';
import { CandidateMovementActions } from './candidate-movement-actions';
import {
    CandidateJoiningScheduleBadge,
    CandidateOfferStatusBadge,
    CandidateOutcomeBadge,
} from './candidate-stage-badge';
import type { StageMoveIntent } from './candidate-stage-move-dialog';

export function CandidateKanbanCard({
    candidate,
    stage,
    onRequestMove,
    onRequestSelect,
    onRequestPrepareOffer,
    onRequestReadiness,
    isDragOverlay = false,
}: {
    candidate: CandidateIndexRow;
    stage: CandidateStage;
    onRequestMove?: (intent: StageMoveIntent) => void;
    onRequestSelect?: (candidate: CandidateIndexRow) => void;
    onRequestPrepareOffer?: (candidate: CandidateIndexRow) => void;
    onRequestReadiness?: (candidate: CandidateIndexRow) => void;
    isDragOverlay?: boolean;
}) {
    const stageColor = STAGE_COLORS[stage];
    const needsAttention = candidateNeedsAttention(candidate);
    const stalled = candidateIsStalled(candidate);
    const title = `${candidate.name} — ${candidate.position_title}`;
    const detailHref = `/organization/recruitment/candidates/${candidate.id}`;
    const didDragRef = useRef(false);
    const canDrag =
        !isDragOverlay &&
        (candidate.move_destinations ?? []).some(
            (destination) =>
                destination.kind !== 'blocked' &&
                destination.kind !== 'select_required',
        );

    const { attributes, listeners, setNodeRef, transform, isDragging } =
        useDraggable({
            id: `candidate-${candidate.id}`,
            data: { candidate, stage },
            disabled: !canDrag,
        });

    useEffect(() => {
        if (isDragging) {
            didDragRef.current = true;
        }
    }, [isDragging]);

    useEffect(() => {
        if (!isDragging && didDragRef.current) {
            const timer = window.setTimeout(() => {
                didDragRef.current = false;
            }, 0);

            return () => window.clearTimeout(timer);
        }
    }, [isDragging]);

    const style = transform
        ? { transform: CSS.Translate.toString(transform) }
        : undefined;

    const openDetail = (event: MouseEvent | KeyboardEvent) => {
        if (
            !shouldNavigateKanbanCardClick({
                isDragging,
                didDrag: didDragRef.current,
                target: event.target,
            })
        ) {
            return;
        }

        router.visit(detailHref);
    };

    // Pointer drag on the whole card; keyboard drag activators stay on the handle
    // so Enter on the card can open detail without conflicting with @dnd-kit.
    const listenerRecord = (listeners ?? {}) as Record<string, unknown>;
    const { onKeyDown: dragKeyDown, ...pointerListeners } = listenerRecord;

    const dragHandleProps =
        canDrag && !isDragOverlay
            ? {
                  ...attributes,
                  onKeyDown: dragKeyDown as
                      | ((event: KeyboardEvent) => void)
                      | undefined,
              }
            : {};

    const cardPointerProps = canDrag && !isDragOverlay ? pointerListeners : {};

    return (
        <article
            ref={isDragOverlay ? undefined : setNodeRef}
            style={isDragOverlay ? undefined : style}
            className={cn(
                'relative rounded-lg border border-border/70 bg-background p-2.5 shadow-xs transition-shadow hover:border-border hover:shadow-sm',
                needsAttention &&
                    'border-amber-500/40 ring-1 ring-amber-500/20 dark:border-amber-400/30',
                stalled &&
                    !needsAttention &&
                    'border-l-2 border-l-amber-400/80',
                isDragging && 'opacity-40',
                isDragOverlay && 'shadow-lg ring-1 ring-primary/30',
                canDrag && 'cursor-grab touch-none active:cursor-grabbing',
                !canDrag && !isDragOverlay && 'cursor-pointer',
            )}
            {...cardPointerProps}
            onClick={isDragOverlay ? undefined : openDetail}
            onKeyDown={
                isDragOverlay
                    ? undefined
                    : (event) => {
                          // Enter opens detail; Space is reserved for keyboard drag on the handle.
                          if (event.key === 'Enter') {
                              if (
                                  shouldNavigateKanbanCardClick({
                                      isDragging,
                                      didDrag: didDragRef.current,
                                      target: event.target,
                                  })
                              ) {
                                  event.preventDefault();
                                  router.visit(detailHref);
                              }
                          }
                      }
            }
            role={isDragOverlay ? undefined : 'link'}
            tabIndex={isDragOverlay ? undefined : 0}
            aria-label={`${candidate.name}, open candidate details`}
        >
            <div
                className={cn(
                    'absolute top-2.5 bottom-2.5 left-0 w-0.5 rounded-full',
                    stageColor.accent,
                )}
                aria-hidden
            />

            <div className="space-y-1.5 pl-2">
                <div className="flex items-start gap-1">
                    {canDrag || isDragOverlay ? (
                        <button
                            type="button"
                            className="mt-0.5 rounded p-0.5 text-muted-foreground/60 hover:bg-muted/60 hover:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            aria-label={`Move ${candidate.name} with keyboard`}
                            data-kanban-interactive="true"
                            {...dragHandleProps}
                            onClick={(event) => event.stopPropagation()}
                        >
                            <GripVertical className="h-3.5 w-3.5" aria-hidden />
                        </button>
                    ) : null}
                    <div className="min-w-0 flex-1">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <p
                                    className="truncate text-sm leading-snug font-semibold text-foreground"
                                    title={title}
                                >
                                    {candidate.name}
                                </p>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-xs">
                                {title}
                            </TooltipContent>
                        </Tooltip>
                        <p className="truncate text-xs text-muted-foreground">
                            {candidate.position_title}
                        </p>
                        <p className="truncate text-[11px] text-muted-foreground/80">
                            {candidate.requirement_number}
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-1">
                    {stage === 'interview' ? (
                        <>
                            <CandidateOutcomeBadge
                                outcome={candidate.interview_outcome}
                                label={candidate.interview_outcome_label}
                            />
                            {candidate.interview_scheduled_at ? (
                                <Badge
                                    variant="outline"
                                    className="h-5 gap-1 border-border/70 px-1.5 text-[10px] font-normal text-muted-foreground"
                                >
                                    <Calendar className="h-3 w-3" />
                                    <span className="max-w-[110px] truncate">
                                        {candidate.interview_scheduled_at}
                                    </span>
                                </Badge>
                            ) : null}
                        </>
                    ) : null}

                    {stage === 'offer_jol' ? (
                        <>
                            <CandidateOfferStatusBadge
                                status={candidate.offer_status}
                                label={candidate.offer_status_label}
                            />
                            {candidate.joining_schedule_label ? (
                                <CandidateJoiningScheduleBadge
                                    urgency={candidate.joining_schedule_urgency}
                                    label={candidate.joining_schedule_label}
                                />
                            ) : null}
                            {candidate.joining_readiness_label ? (
                                <Badge
                                    variant="outline"
                                    className="h-5 px-1.5 text-[10px] font-normal"
                                >
                                    {candidate.joining_readiness_label}
                                </Badge>
                            ) : null}
                        </>
                    ) : null}

                    {stage === 'joined' ? (
                        <>
                            {candidate.actual_joining_date ? (
                                <Badge
                                    variant="outline"
                                    className="h-5 border-emerald-600/30 px-1.5 text-[10px] font-normal text-emerald-700 dark:text-emerald-400"
                                >
                                    Joined {candidate.actual_joining_date}
                                </Badge>
                            ) : null}
                            {candidate.conversion_status === 'pending' ? (
                                <Badge
                                    variant="outline"
                                    className="h-5 border-amber-500/40 px-1.5 text-[10px] font-normal text-amber-800 dark:text-amber-300"
                                >
                                    Needs conversion
                                </Badge>
                            ) : null}
                            {candidate.conversion_status === 'converted' ? (
                                <Badge
                                    variant="outline"
                                    className="h-5 px-1.5 text-[10px] font-normal"
                                >
                                    Converted
                                </Badge>
                            ) : null}
                        </>
                    ) : null}

                    {stage === 'rejected' &&
                    candidate.interview_outcome_label ? (
                        <CandidateOutcomeBadge
                            outcome={candidate.interview_outcome}
                            label={candidate.interview_outcome_label}
                        />
                    ) : null}
                </div>

                {stage === 'offer_jol' &&
                candidate.joining_blocker_notes?.trim() ? (
                    <div className="flex items-start gap-1.5 rounded-md bg-amber-50 px-2 py-1 text-[11px] text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                        <span className="line-clamp-2">
                            {candidate.joining_blocker_notes}
                        </span>
                    </div>
                ) : null}

                {!isDragOverlay ? (
                    <div
                        className="border-t border-border/40 pt-2"
                        data-kanban-interactive="true"
                        onPointerDown={(event) => event.stopPropagation()}
                        onClick={(event) => event.stopPropagation()}
                        onKeyDown={(event) => event.stopPropagation()}
                    >
                        <CandidateMovementActions
                            candidate={candidate}
                            density="compact"
                            onRequestMove={onRequestMove}
                            onRequestSelect={onRequestSelect}
                            onRequestPrepareOffer={onRequestPrepareOffer}
                            onRequestReadiness={onRequestReadiness}
                        />
                    </div>
                ) : null}
            </div>
        </article>
    );
}
