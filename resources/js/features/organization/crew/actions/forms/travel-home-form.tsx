import type { ReactElement } from 'react';
import { formatDisplayDate } from '@/lib/format-date';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function TravelHomeForm({
    form,
    config,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    return (
        <div className="space-y-4">
            <div className="rounded-lg border bg-muted/20 p-3 text-sm">
                <span className="text-muted-foreground">
                    Planned travel home:{' '}
                </span>
                <span className="font-medium">
                    {formatDisplayDate(context.planned_travel_at)}
                </span>
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
