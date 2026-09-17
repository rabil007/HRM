import type { InertiaFormProps } from '@inertiajs/react';
import type { ReactElement, RefObject } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    formatCompanyTimezoneLabel,
    formatDisplayDateTime12hInTimezone,
    isCompanyTimeInFuture,
    nowInCompanyTime,
    useCompanyTimezone,
} from '@/lib/company-timezone';
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
    timezone,
    onValueChange,
}: {
    form: InertiaFormProps<CrewMovementActionFormData>;
    label: string;
    inputRef?: RefObject<HTMLInputElement | HTMLTextAreaElement | null>;
    id?: string;
    min?: string;
    timezone?: string;
    onValueChange?: (value: string) => void;
}): ReactElement {
    const effectiveTimezone = useCompanyTimezone(timezone);
    const timezoneLabel = formatCompanyTimezoneLabel(effectiveTimezone);
    const companyNow = nowInCompanyTime(effectiveTimezone);
    const isFuture = isCompanyTimeInFuture(
        form.data.occurred_at,
        effectiveTimezone,
    );

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
                max={companyNow}
                onChange={(event) => {
                    const value = event.target.value;
                    form.setData('occurred_at', value);
                    onValueChange?.(value);
                }}
                required
                aria-required="true"
            />
            <p className="text-xs text-muted-foreground">
                Recorded in company time: {timezoneLabel}.
            </p>
            {isFuture ? (
                <p className="text-xs text-destructive">
                    Movement time cannot be in the future (current company time:{' '}
                    {formatDisplayDateTime12hInTimezone(
                        companyNow,
                        effectiveTimezone,
                    )}
                    ).
                </p>
            ) : null}
            <InputError message={form.errors.occurred_at} />
        </div>
    );
}
