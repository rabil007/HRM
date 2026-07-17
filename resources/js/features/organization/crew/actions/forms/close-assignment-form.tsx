import type { ReactElement } from 'react';
import { formatDisplayDate } from '@/lib/format-date';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function CloseAssignmentForm({
    form,
    config,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    return (
        <div className="space-y-4">
            <div className="space-y-1 rounded-lg border bg-muted/20 p-3 text-sm">
                <div>
                    <span className="text-muted-foreground">P6 started: </span>
                    <span className="font-medium">
                        {formatDisplayDate(
                            context.current_phase_started_at?.slice(0, 10) ??
                                null,
                        )}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">Actual join: </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.actual_join_at)}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Actual disembarkation:{' '}
                    </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.actual_disembarkation_at)}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Travel-home date:{' '}
                    </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.planned_travel_at)}
                    </span>
                </div>
            </div>

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
