import type { ReactElement } from 'react';
import { useEffect, useRef } from 'react';
import { ActionImpactPreview } from '@/components/action-impact-preview';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { CrewPreJoinAccommodationContext } from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

function resolveOpenHotelAccommodation(
    preJoin?: CrewPreJoinAccommodationContext,
    postSignoff?: CrewPreJoinAccommodationContext,
): CrewPreJoinAccommodationContext | null {
    if (preJoin?.status === 'open_hotel') {
        return preJoin;
    }

    if (postSignoff?.status === 'open_hotel') {
        return postSignoff;
    }

    return null;
}

export function CancelAssignmentForm({
    form,
    config,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const openAccommodation = resolveOpenHotelAccommodation(
        context.pre_join_accommodation,
        context.post_signoff_accommodation,
    );
    const accommodationIntegrityWarning =
        context.pre_join_accommodation?.warning ??
        context.post_signoff_accommodation?.warning ??
        null;

    const lastAutoCheckOutDateRef = useRef(
        form.data.check_out_date || form.data.occurred_at.slice(0, 10),
    );

    const syncCheckOutDate = (occurredAt: string): void => {
        if (openAccommodation === null) {
            return;
        }

        const nextCancellationDate = occurredAt.slice(0, 10);

        if (
            !nextCancellationDate ||
            (form.data.check_out_date !== '' &&
                form.data.check_out_date !== lastAutoCheckOutDateRef.current)
        ) {
            return;
        }

        form.setData('check_out_date', nextCancellationDate);
        lastAutoCheckOutDateRef.current = nextCancellationDate;
    };

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

            {accommodationIntegrityWarning ? (
                <ActionImpactPreview
                    severity="destructive"
                    title="Accommodation issue"
                    impacts={[
                        accommodationIntegrityWarning,
                        'Resolve accommodation data before cancelling this assignment.',
                    ]}
                />
            ) : null}

            {openAccommodation !== null ? (
                <div className="space-y-4 rounded-lg border border-border/60 p-4">
                    <div>
                        <h3 className="text-sm font-semibold">
                            Current Accommodation
                        </h3>
                    </div>
                    <div className="space-y-2 text-sm">
                        <div className="font-medium">
                            {openAccommodation.hotel_name ?? 'Hotel'}
                        </div>
                        {openAccommodation.room_type_name ? (
                            <div className="text-muted-foreground">
                                {openAccommodation.room_type_name}
                            </div>
                        ) : null}
                        <div className="text-muted-foreground">
                            Checked in:{' '}
                            {formatDisplayDate(openAccommodation.check_in_date)}
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="movement-cancel-check-out-date">
                            Hotel Check-out Date{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="movement-cancel-check-out-date"
                            type="date"
                            value={form.data.check_out_date}
                            onChange={(event) =>
                                form.setData(
                                    'check_out_date',
                                    event.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.check_out_date} />
                    </div>
                </div>
            ) : null}

            {openAccommodation === null && form.errors.check_out_date ? (
                <InputError message={form.errors.check_out_date} />
            ) : null}

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    timezone={context.company_timezone}
                    allowFutureActualMovementDates={Boolean(
                        context.allow_future_actual_movement_dates,
                    )}
                    inputRef={
                        openAccommodation !== null ? undefined : firstFieldRef
                    }
                    onValueChange={syncCheckOutDate}
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
