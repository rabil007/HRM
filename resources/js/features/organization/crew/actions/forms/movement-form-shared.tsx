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
import {
    resolveMovementOccurredAtMax,
    resolveMovementOccurredAtMin,
    shouldShowFutureMovementWarning,
} from '../../lib/future-actual-movement-dates';
import { applyLinkedHotelDatesOnMovementChange } from '../../lib/linked-hotel-date';
import type { LinkedHotelDateBinding } from '../../lib/linked-hotel-date';
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
    schedulingMode?: boolean;
    allowsFutureDates?: boolean;
    preventPastSelection?: boolean;
};

export function MovementOccurredAtField({
    form,
    label,
    inputRef,
    id = 'movement-occurred-at',
    min,
    timezone,
    schedulingMode = false,
    allowsFutureDates = false,
    preventPastSelection = false,
    linkedHotelDates = [],
    companyNow: companyNowOverride,
}: {
    form: InertiaFormProps<CrewMovementActionFormData>;
    label: string;
    inputRef?: RefObject<HTMLInputElement | HTMLTextAreaElement | null>;
    id?: string;
    min?: string;
    timezone?: string;
    schedulingMode?: boolean;
    allowsFutureDates?: boolean;
    preventPastSelection?: boolean;
    linkedHotelDates?: readonly LinkedHotelDateBinding[];
    companyNow?: string;
}): ReactElement {
    if (timezone) {
        return (
            <MovementOccurredAtFieldResolved
                form={form}
                label={label}
                inputRef={inputRef}
                id={id}
                min={min}
                timezone={timezone}
                schedulingMode={schedulingMode}
                allowsFutureDates={allowsFutureDates}
                preventPastSelection={preventPastSelection}
                linkedHotelDates={linkedHotelDates}
                companyNow={companyNowOverride}
            />
        );
    }

    return (
        <MovementOccurredAtFieldFromPage
            form={form}
            label={label}
            inputRef={inputRef}
            id={id}
            min={min}
            schedulingMode={schedulingMode}
            allowsFutureDates={allowsFutureDates}
            preventPastSelection={preventPastSelection}
            linkedHotelDates={linkedHotelDates}
            companyNow={companyNowOverride}
        />
    );
}

function MovementOccurredAtFieldFromPage(
    props: Omit<
        Parameters<typeof MovementOccurredAtFieldResolved>[0],
        'timezone'
    >,
): ReactElement {
    const timezone = useCompanyTimezone();

    return <MovementOccurredAtFieldResolved {...props} timezone={timezone} />;
}

function MovementOccurredAtFieldResolved({
    form,
    label,
    inputRef,
    id = 'movement-occurred-at',
    min,
    timezone,
    schedulingMode = false,
    allowsFutureDates = false,
    preventPastSelection = false,
    linkedHotelDates = [],
    companyNow: companyNowOverride,
}: {
    form: InertiaFormProps<CrewMovementActionFormData>;
    label: string;
    inputRef?: RefObject<HTMLInputElement | HTMLTextAreaElement | null>;
    id?: string;
    min?: string;
    timezone: string;
    schedulingMode?: boolean;
    allowsFutureDates?: boolean;
    preventPastSelection?: boolean;
    linkedHotelDates?: readonly LinkedHotelDateBinding[];
    companyNow?: string;
}): ReactElement {
    const effectiveTimezone = timezone;
    const timezoneLabel = formatCompanyTimezoneLabel(effectiveTimezone);
    const companyNow =
        companyNowOverride ?? nowInCompanyTime(effectiveTimezone);
    const isFuture = companyNowOverride
        ? form.data.occurred_at.slice(0, 16) > companyNow.slice(0, 16)
        : isCompanyTimeInFuture(form.data.occurred_at, effectiveTimezone);
    const max = resolveMovementOccurredAtMax(companyNow, allowsFutureDates);
    const resolvedMin = resolveMovementOccurredAtMin(companyNow, {
        preventPastSelection,
        chronologyMin: min,
    });
    const showFutureWarning = shouldShowFutureMovementWarning(
        isFuture,
        allowsFutureDates,
    );

    return (
        <div className="space-y-2" data-slot="movement-occurred-at">
            <Label htmlFor={id}>
                {label} <span className="text-destructive">*</span>
            </Label>
            <Input
                id={id}
                ref={inputRef as RefObject<HTMLInputElement | null> | undefined}
                type="datetime-local"
                value={form.data.occurred_at}
                min={resolvedMin}
                max={max}
                onChange={(event) => {
                    const value = event.target.value;

                    form.setData((current) =>
                        applyLinkedHotelDatesOnMovementChange(
                            current,
                            value,
                            linkedHotelDates,
                        ),
                    );
                }}
                required
                aria-required="true"
            />
            <p className="text-xs text-muted-foreground">
                {schedulingMode
                    ? `Scheduled in company time: ${timezoneLabel}.`
                    : `Recorded in company time: ${timezoneLabel}.`}
            </p>
            {showFutureWarning ? (
                <p className="text-xs text-destructive">
                    This movement action cannot be scheduled for a future date
                    and time (current company time:{' '}
                    {formatDisplayDateTime12hInTimezone(
                        companyNow,
                        effectiveTimezone,
                    )}
                    ).
                </p>
            ) : null}
            <InputError
                message={
                    schedulingMode
                        ? (form.errors.scheduled_at ?? form.errors.occurred_at)
                        : form.errors.occurred_at
                }
            />
        </div>
    );
}
