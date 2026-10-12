import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import type {
    CandidateInterviewCycleSnapshot,
    CandidateMovementEvent,
} from '../types';

function actionTone(action: string): string {
    switch (action) {
        case 'selected':
            return 'bg-emerald-500/80';
        case 'rejected':
        case 'offer_rejected':
            return 'bg-rose-500/80';
        case 'reopen_rejected':
        case 'undo_selected':
        case 'stage_corrected':
        case 'joining_corrected':
            return 'bg-amber-500/80';
        case 'offer_prepared':
        case 'offer_updated':
        case 'offer_sent':
        case 'offer_accepted':
        case 'offer_revised':
            return 'bg-sky-500/80';
        case 'joined':
        case 'joining_readiness_updated':
            return 'bg-teal-500/80';
        case 'employee_converted':
        case 'employee_linked':
            return 'bg-violet-500/80';
        default:
            return 'bg-foreground/60';
    }
}

function SnapshotDetails({
    snapshot,
    title,
}: {
    snapshot: CandidateInterviewCycleSnapshot;
    title: string;
}) {
    const [open, setOpen] = useState(false);
    const hasContent = Boolean(
        snapshot.interview_scheduled_at ||
        snapshot.interviewer_user_name ||
        snapshot.external_interviewer_name ||
        snapshot.interview_mode_label ||
        snapshot.interview_location ||
        snapshot.interview_notes ||
        snapshot.interview_feedback ||
        snapshot.interview_outcome_label,
    );

    if (!hasContent) {
        return null;
    }

    return (
        <Collapsible open={open} onOpenChange={setOpen} className="mt-1.5">
            <CollapsibleTrigger className="flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground">
                {title}
                <ChevronDown
                    className={cn(
                        'h-3.5 w-3.5 transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </CollapsibleTrigger>
            <CollapsibleContent className="mt-1.5 space-y-1 rounded-md border border-border/50 bg-muted/30 px-2.5 py-2 text-xs">
                {snapshot.interview_scheduled_at ? (
                    <p>
                        <span className="text-muted-foreground">
                            Scheduled:{' '}
                        </span>
                        {snapshot.interview_scheduled_at}
                    </p>
                ) : null}
                {snapshot.interview_mode_label ? (
                    <p>
                        <span className="text-muted-foreground">Mode: </span>
                        {snapshot.interview_mode_label}
                    </p>
                ) : null}
                {snapshot.interviewer_user_name ? (
                    <p>
                        <span className="text-muted-foreground">
                            Internal interviewer:{' '}
                        </span>
                        {snapshot.interviewer_user_name}
                    </p>
                ) : null}
                {snapshot.external_interviewer_name ? (
                    <p>
                        <span className="text-muted-foreground">
                            External interviewer:{' '}
                        </span>
                        {snapshot.external_interviewer_name}
                    </p>
                ) : null}
                {snapshot.interview_location ? (
                    <p>
                        <span className="text-muted-foreground">
                            Location:{' '}
                        </span>
                        {snapshot.interview_location}
                    </p>
                ) : null}
                {snapshot.interview_outcome_label ? (
                    <p>
                        <span className="text-muted-foreground">Outcome: </span>
                        {snapshot.interview_outcome_label}
                    </p>
                ) : null}
                {snapshot.interview_notes ? (
                    <div>
                        <p className="text-muted-foreground">
                            Preparation notes
                        </p>
                        <p className="whitespace-pre-wrap">
                            {snapshot.interview_notes}
                        </p>
                    </div>
                ) : null}
                {snapshot.interview_feedback ? (
                    <div>
                        <p className="text-muted-foreground">Feedback</p>
                        <p className="whitespace-pre-wrap">
                            {snapshot.interview_feedback}
                        </p>
                    </div>
                ) : null}
            </CollapsibleContent>
        </Collapsible>
    );
}

function MovementEventItem({ event }: { event: CandidateMovementEvent }) {
    const stageChanged =
        event.from_stage_label &&
        event.to_stage_label &&
        event.from_stage !== event.to_stage;
    const outcomeChanged =
        event.from_outcome_label || event.to_outcome_label
            ? `${event.from_outcome_label || '—'} → ${event.to_outcome_label || '—'}`
            : null;
    const snapshot =
        event.context?.interview_cycle_snapshot ??
        event.context?.prior_interview_cycle_snapshot ??
        null;
    const feedbackChange = event.context?.interview_feedback;

    return (
        <li className="relative pb-4 last:pb-0">
            <span
                className={cn(
                    'absolute top-1.5 -left-[1.3125rem] h-2.5 w-2.5 rounded-full border border-background',
                    actionTone(event.action),
                )}
                aria-hidden
            />
            <div className="space-y-1 text-sm">
                <div className="font-medium text-foreground">
                    {event.action_label}
                </div>
                {stageChanged ? (
                    <div className="text-muted-foreground">
                        {event.from_stage_label} → {event.to_stage_label}
                    </div>
                ) : null}
                {outcomeChanged ? (
                    <div className="text-xs text-muted-foreground">
                        Outcome: {outcomeChanged}
                    </div>
                ) : null}
                {feedbackChange?.to ? (
                    <p className="rounded-md bg-muted/40 px-2 py-1 text-xs whitespace-pre-wrap text-foreground/90">
                        Feedback recorded
                        {feedbackChange.from
                            ? ' (updated from prior notes)'
                            : ''}
                        : {feedbackChange.to}
                    </p>
                ) : null}
                {event.reason ? (
                    <p className="rounded-md bg-muted/40 px-2 py-1 text-xs text-foreground/90">
                        {event.reason}
                    </p>
                ) : null}
                {snapshot ? (
                    <SnapshotDetails
                        snapshot={snapshot}
                        title="Previous Interview Details"
                    />
                ) : null}
                <p className="text-xs text-muted-foreground">
                    {event.performed_by_name || 'System'} ·{' '}
                    {event.created_at || '—'}
                </p>
            </div>
        </li>
    );
}

export function CandidateMovementHistory({
    events,
}: {
    events: CandidateMovementEvent[];
}) {
    if (events.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No recruitment movements recorded yet.
            </p>
        );
    }

    const chronological = [...events].sort((a, b) => a.id - b.id);

    return (
        <ol className="relative space-y-0 border-l border-border/60 pl-4">
            {chronological.map((event) => (
                <MovementEventItem key={event.id} event={event} />
            ))}
        </ol>
    );
}
