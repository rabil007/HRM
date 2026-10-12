import { Link } from '@inertiajs/react';
import { AlertTriangle, Calendar, FileText, Mail, Phone } from 'lucide-react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import {
    daysInStageLabel,
    getInitials,
    STAGE_COLORS,
} from '../lib/kanban-stage-meta';
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
    const initials = getInitials(candidate.name);
    const stageColor = STAGE_COLORS[stage];
    const ageLabel = daysInStageLabel(candidate.days_in_stage);
    const contact = [candidate.email, candidate.phone]
        .filter(Boolean)
        .join(' · ');
    const showJoiningCue =
        stage === 'offer_jol' ||
        stage === 'joining' ||
        Boolean(candidate.joining_schedule_label);
    const showBlocker =
        stage === 'offer_jol' &&
        Boolean(candidate.joining_blocker_notes?.trim());

    return (
        <div
            className={cn(
                'group relative flex flex-col gap-2.5 rounded-lg border border-border/70 bg-background p-3 shadow-xs transition-shadow hover:border-border hover:shadow-sm',
            )}
        >
            <div
                className={cn(
                    'absolute top-3 bottom-3 left-0 w-0.5 rounded-full',
                    stageColor.accent,
                )}
                aria-hidden
            />

            <div className="flex items-start gap-2 pl-1.5">
                <Avatar className="mt-0.5 size-7 shrink-0">
                    <AvatarFallback className="text-[10px] font-semibold">
                        {initials}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <div className="flex items-start gap-1.5">
                        <Link
                            href={`/organization/recruitment/candidates/${candidate.id}`}
                            className="min-w-0 flex-1 truncate text-sm leading-tight font-semibold text-foreground hover:text-primary hover:underline"
                        >
                            {candidate.name}
                        </Link>
                        {candidate.has_cv ? (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="mt-0.5 shrink-0 text-muted-foreground/50">
                                        <FileText className="h-3.5 w-3.5" />
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>CV attached</TooltipContent>
                            </Tooltip>
                        ) : null}
                    </div>
                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                        {candidate.position_title}
                    </p>
                    <p className="mt-0.5 truncate text-[11px] text-muted-foreground/80">
                        <span className="font-medium text-foreground/65">
                            {candidate.requirement_number}
                        </span>
                        {candidate.source_label ? (
                            <span> · {candidate.source_label}</span>
                        ) : null}
                    </p>
                </div>
            </div>

            {contact ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <button
                            type="button"
                            className="inline-flex w-fit items-center gap-1 pl-1.5 text-muted-foreground/70 hover:text-muted-foreground"
                            aria-label={contact}
                        >
                            {candidate.email ? (
                                <Mail className="h-3.5 w-3.5" />
                            ) : (
                                <Phone className="h-3.5 w-3.5" />
                            )}
                            <span className="text-[11px]">Contact</span>
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>{contact}</TooltipContent>
                </Tooltip>
            ) : null}

            <div className="flex flex-wrap items-center gap-1 pl-1.5">
                {ageLabel ? (
                    <Badge
                        variant="outline"
                        className="h-5 border-border/70 px-1.5 text-[10px] font-normal text-muted-foreground"
                    >
                        {ageLabel}
                    </Badge>
                ) : null}
                <CandidateOutcomeBadge
                    outcome={candidate.interview_outcome}
                    label={candidate.interview_outcome_label}
                />
                <CandidateOfferStatusBadge
                    status={candidate.offer_status}
                    label={candidate.offer_status_label}
                />
                {showJoiningCue && candidate.joining_schedule_label ? (
                    <CandidateJoiningScheduleBadge
                        urgency={candidate.joining_schedule_urgency}
                        label={candidate.joining_schedule_label}
                    />
                ) : null}
                {stage === 'offer_jol' && candidate.joining_readiness_label ? (
                    <Badge
                        variant="outline"
                        className="h-5 px-1.5 text-[10px] font-normal"
                    >
                        {candidate.joining_readiness_label}
                    </Badge>
                ) : null}
                {stage === 'interview' && candidate.interview_scheduled_at ? (
                    <Badge
                        variant="outline"
                        className="h-5 gap-1 border-border/70 px-1.5 text-[10px] font-normal text-muted-foreground"
                    >
                        <Calendar className="h-3 w-3" />
                        {candidate.interview_scheduled_at}
                    </Badge>
                ) : null}
                {stage === 'joined' && candidate.actual_joining_date ? (
                    <Badge
                        variant="outline"
                        className="h-5 border-emerald-600/30 px-1.5 text-[10px] font-normal text-emerald-600 dark:text-emerald-400"
                    >
                        Joined: {candidate.actual_joining_date}
                    </Badge>
                ) : null}
            </div>

            {showBlocker ? (
                <div className="flex items-start gap-1.5 rounded-md bg-amber-50 px-2 py-1.5 text-[11px] text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                    <span className="line-clamp-2">
                        {candidate.joining_blocker_notes}
                    </span>
                </div>
            ) : null}

            <div className="border-t border-border/40 pt-2 pl-1.5">
                <CandidateMovementActions
                    candidate={candidate}
                    density="compact"
                />
            </div>
        </div>
    );
}
