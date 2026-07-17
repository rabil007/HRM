import type { ReactElement } from 'react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function CancelAssignmentForm({
    form,
    config,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    useEffect(() => {
        if (!config.occurredAtLabel) {
            firstFieldRef?.current?.focus();
        }
    }, [config.occurredAtLabel, firstFieldRef]);

    return (
        <div className="space-y-4">
            <div className="space-y-1 rounded-lg border bg-muted/20 p-3 text-sm">
                <div>
                    <span className="text-muted-foreground">Employee: </span>
                    <span className="font-medium">
                        {[context.employee_name, context.employee_no]
                            .filter(Boolean)
                            .join(' · ') || '—'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Assignment number:{' '}
                    </span>
                    <span className="font-medium">{context.assignment_no}</span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Current phase:{' '}
                    </span>
                    <span className="font-medium">
                        {context.current_phase_code
                            ? `${context.current_phase_code.toUpperCase()} · ${context.current_phase_label ?? ''}`
                            : 'None'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Planning impact:{' '}
                    </span>
                    <span className="font-medium">
                        Before P4, the linked future Planning bar will be
                        removed.
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

            <div className="space-y-2">
                <Label htmlFor="movement-reason">
                    Cancellation reason{' '}
                    <span className="text-destructive">*</span>
                </Label>
                <Textarea
                    id="movement-reason"
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    rows={3}
                    required
                    aria-required="true"
                />
                <InputError message={form.errors.reason} />
            </div>
        </div>
    );
}
