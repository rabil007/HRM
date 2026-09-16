import type { ReactElement } from 'react';
import { MovementNextPhaseChoice } from '../movement-next-phase-choice';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function RecordArrivalForm({
    form,
    config,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const isFixedArrivalPhase =
        context.current_phase_code === 'p0' ||
        context.current_phase_code === 'p1';

    return (
        <div className="space-y-4">
            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    inputRef={firstFieldRef}
                />
            ) : null}
            {isFixedArrivalPhase ? (
                <div className="rounded-md border border-border/60 bg-muted/40 p-3 text-sm text-muted-foreground">
                    This records the crew member's actual arrival and starts
                    Join Standby.
                </div>
            ) : config.nextPhaseOptions && config.nextPhaseLabel ? (
                <MovementNextPhaseChoice
                    id="movement-next-phase"
                    label={config.nextPhaseLabel}
                    value={form.data.next_phase}
                    options={config.nextPhaseOptions}
                    onChange={(value) => form.setData('next_phase', value)}
                    error={form.errors.next_phase}
                />
            ) : null}
        </div>
    );
}
