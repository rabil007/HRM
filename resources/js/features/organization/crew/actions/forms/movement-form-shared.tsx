import type { InertiaFormProps } from '@inertiajs/react';
import type { ReactElement, RefObject } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    CrewAssignmentFormOptions,
    CrewMovementActionFormData,
    CrewMovementContext,
} from '../../types';
import type { MovementActionConfig } from '../movement-action-config';

export type MovementActionFormProps = {
    form: InertiaFormProps<CrewMovementActionFormData>;
    config: MovementActionConfig;
    context: CrewMovementContext;
    formOptions?: CrewAssignmentFormOptions;
    firstFieldRef?: RefObject<HTMLInputElement | HTMLTextAreaElement | null>;
};

export function MovementOccurredAtField({
    form,
    label,
    inputRef,
    id = 'movement-occurred-at',
    min,
}: {
    form: InertiaFormProps<CrewMovementActionFormData>;
    label: string;
    inputRef?: RefObject<HTMLInputElement | HTMLTextAreaElement | null>;
    id?: string;
    min?: string;
}): ReactElement {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>
                {label} <span className="text-destructive">*</span>
            </Label>
            <Input
                id={id}
                ref={inputRef as RefObject<HTMLInputElement | null> | undefined}
                type="datetime-local"
                value={form.data.occurred_at}
                min={min}
                onChange={(event) =>
                    form.setData('occurred_at', event.target.value)
                }
                required
                aria-required="true"
            />
            <p className="text-xs text-muted-foreground">
                Recorded in the company timezone.
            </p>
            <InputError message={form.errors.occurred_at} />
        </div>
    );
}
