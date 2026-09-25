import type { ReactElement } from 'react';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function MarkReadyForm({
    form,
    config,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    return (
        <div className="space-y-4">
            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    timezone={context.company_timezone}
                    allowFutureActualMovementDates={Boolean(
                        context.allow_future_actual_movement_dates,
                    )}
                    inputRef={firstFieldRef}
                />
            ) : null}
        </div>
    );
}
