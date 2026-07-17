import type { ReactElement } from 'react';
import { formatDisplayDate } from '@/lib/format-date';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function ApproveMobilisationForm({
    form,
    config,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const missing: string[] = [];

    if (!context.vessel_name) {
        missing.push('Vessel is not set yet.');
    }

    if (!context.rank_name) {
        missing.push('Rank is not set yet.');
    }

    if (!context.planned_travel_at) {
        missing.push('Planned travel home is not set.');
    }

    if (!context.planned_join_at) {
        missing.push('Planned join is not set.');
    }

    return (
        <div className="space-y-4">
            <div className="space-y-1 rounded-lg border bg-muted/20 p-3 text-sm">
                <div>
                    <span className="text-muted-foreground">
                        Current phase:{' '}
                    </span>
                    <span className="font-medium">
                        {context.current_phase_code
                            ? `${context.current_phase_code.toUpperCase()} ${context.current_phase_label ?? ''}`
                            : 'None'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Planned travel:{' '}
                    </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.planned_travel_at)}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Planned join:{' '}
                    </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.planned_join_at)}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">Vessel: </span>
                    <span className="font-medium">
                        {context.vessel_name ?? 'Not set'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">Rank: </span>
                    <span className="font-medium">
                        {context.rank_name ?? 'Not set'}
                    </span>
                </div>
            </div>

            {missing.length > 0 ? (
                <div className="rounded-lg border border-amber-500/30 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
                    <ul className="list-disc space-y-1 pl-4">
                        {missing.map((item) => (
                            <li key={item}>{item}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    inputRef={firstFieldRef}
                />
            ) : null}
        </div>
    );
}
