import type { CandidateMovementEvent } from '../types';

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

    return (
        <ol className="relative space-y-0 border-l border-border/60 pl-4">
            {events.map((event) => (
                <li key={event.id} className="relative pb-4 last:pb-0">
                    <span
                        className="absolute top-1.5 -left-[1.3125rem] h-2.5 w-2.5 rounded-full border border-background bg-foreground/70"
                        aria-hidden
                    />
                    <div className="space-y-1 text-sm">
                        <div className="font-medium text-foreground">
                            {event.action_label}
                        </div>
                        <div className="text-muted-foreground">
                            {event.from_stage_label || '—'} →{' '}
                            {event.to_stage_label}
                            {event.from_outcome_label ||
                            event.to_outcome_label ? (
                                <span>
                                    {' '}
                                    ({event.from_outcome_label || '—'}
                                    {' → '}
                                    {event.to_outcome_label || '—'})
                                </span>
                            ) : null}
                        </div>
                        {event.reason ? (
                            <p className="rounded-md bg-muted/40 px-2 py-1 text-xs text-foreground/90">
                                {event.reason}
                            </p>
                        ) : null}
                        <p className="text-xs text-muted-foreground">
                            {event.performed_by_name || 'System'} ·{' '}
                            {event.created_at || '—'}
                        </p>
                    </div>
                </li>
            ))}
        </ol>
    );
}
