import { Link } from '@inertiajs/react';
import { AlertTriangle, Calendar } from 'lucide-react';
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
import { daysInStageLabel, STAGE_COLORS } from '../lib/kanban-stage-meta';
import type { CandidateIndexRow, CandidateStage } from '../types';
import { CandidateMovementActions } from './candidate-movement-actions';
import {
    CandidateJoiningScheduleBadge,
    CandidateOfferStatusBadge,
    CandidateOutcomeBadge,
} from './candidate-stage-badge';

export function CandidateKanbanCard({
    candidate,
    stage,
}: {
    candidate: CandidateIndexRow;
    stage: CandidateStage;
}) {
    const stageColor = STAGE_COLORS[stage];
    const ageLabel = daysInStageLabel(candidate.days_in_stage);
    const needsAttention = candidateNeedsAttention(candidate);
    const stalled = candidateIsStalled(candidate);
    const title = `${candidate.name} — ${candidate.position_title}`;

    return (
        <article
            className={cn(
                'relative rounded-lg border border-border/70 bg-background p-2.5 shadow-xs transition-shadow hover:border-border hover:shadow-sm',
                needsAttention &&
                    'border-amber-500/40 ring-1 ring-amber-500/20 dark:border-amber-400/30',
                stalled &&
                    !needsAttention &&
                    'border-l-2 border-l-amber-400/80',
            )}
        >
            <div
                className={cn(
                    'absolute top-2.5 bottom-2.5 left-0 w-0.5 rounded-full',
                    stageColor.accent,
                )}
                aria-hidden
            />

            <div className="space-y-1.5 pl-2">
                <div className="min-w-0">
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Link
                                href={`/organization/recruitment/candidates/${candidate.id}`}
                                className="block truncate text-sm leading-snug font-semibold text-foreground hover:text-primary hover:underline"
                                title={title}
                            >
                                {candidate.name}
                            </Link>
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

                <div className="flex flex-wrap items-center gap-1">
                    {ageLabel ? (
                        <Badge
                            variant="outline"
                            className={cn(
                                'h-5 border-border/70 px-1.5 text-[10px] font-normal text-muted-foreground',
                                stalled &&
                                    'border-amber-500/40 text-amber-800 dark:text-amber-300',
                            )}
                        >
                            {ageLabel}
                        </Badge>
                    ) : null}

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

                <div className="border-t border-border/40 pt-2">
                    <CandidateMovementActions
                        candidate={candidate}
                        density="compact"
                    />
                </div>
            </div>
        </article>
    );
}
