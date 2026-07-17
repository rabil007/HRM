import type { ReactElement } from 'react';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function MarkReadyForm({
    form,
    config,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    return (
        <div className="space-y-4">
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
