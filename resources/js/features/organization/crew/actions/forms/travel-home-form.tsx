import type { ReactElement } from 'react';
import {
    setHotelDateLink,
    setManualHotelDate,
} from '@/features/organization/crew/lib/linked-hotel-date';
import { formatDisplayDate } from '@/lib/format-date';
import { MovementNextPhaseChoice } from '../movement-next-phase-choice';
import { LinkedHotelDateField } from './linked-hotel-date-field';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function TravelHomeForm({
    form,
    config,
    context,
    firstFieldRef,
    schedulingMode,
    allowsFutureDates,
    preventPastSelection,
}: MovementActionFormProps): ReactElement {
    const postSignoffAccommodation = context.post_signoff_accommodation;
    const checkOutLinked = form.data.check_out_date_auto_synced === true;

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

            {postSignoffAccommodation?.status === 'open_hotel' ? (
                <div className="space-y-4 rounded-lg border border-border/60 p-4">
                    <div>
                        <h3 className="text-sm font-semibold">
                            Current Accommodation
                        </h3>
                    </div>
                    <div className="space-y-2 text-sm">
                        <div className="font-medium">
                            {postSignoffAccommodation.hotel_name ?? 'Hotel'}
                        </div>
                        {postSignoffAccommodation.room_type_name ? (
                            <div className="text-muted-foreground">
                                {postSignoffAccommodation.room_type_name}
                            </div>
                        ) : null}
                        <div className="text-muted-foreground">
                            Checked in{' '}
                            {formatDisplayDate(
                                postSignoffAccommodation.check_in_date,
                            )}
                        </div>
                        {postSignoffAccommodation.stay_days !== null ? (
                            <div className="text-muted-foreground">
                                Hotel days: {postSignoffAccommodation.stay_days}{' '}
                                day
                                {postSignoffAccommodation.stay_days === 1
                                    ? ''
                                    : 's'}
                            </div>
                        ) : null}
                    </div>
                    <LinkedHotelDateField
                        id="movement-post-signoff-check-out-date"
                        label="Hotel Check-Out"
                        checkboxLabel="Use Return Home Date for Hotel Check-Out"
                        value={form.data.check_out_date}
                        linked={checkOutLinked}
                        error={form.errors.check_out_date}
                        onLinkedChange={(linked) =>
                            form.setData((current) =>
                                setHotelDateLink(
                                    current,
                                    'check_out_date',
                                    'check_out_date_auto_synced',
                                    linked,
                                ),
                            )
                        }
                        onValueChange={(value) =>
                            form.setData((current) =>
                                setManualHotelDate(
                                    current,
                                    'check_out_date',
                                    'check_out_date_auto_synced',
                                    value,
                                ),
                            )
                        }
                    />
                </div>
            ) : null}

            {postSignoffAccommodation?.status === 'no_accommodation' ? (
                <div className="rounded-lg border border-border/60 bg-muted/20 p-3 text-sm">
                    <div className="font-medium">Accommodation</div>
                    <p className="mt-1 text-muted-foreground">
                        No hotel accommodation recorded.
                    </p>
                </div>
            ) : null}

            {postSignoffAccommodation?.status === 'missing' &&
            postSignoffAccommodation.warning ? (
                <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200">
                    {postSignoffAccommodation.warning}
                </div>
            ) : null}

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    timezone={context.company_timezone}
                    schedulingMode={Boolean(schedulingMode)}
                    allowsFutureDates={Boolean(allowsFutureDates)}
                    preventPastSelection={Boolean(preventPastSelection)}
                    inputRef={
                        postSignoffAccommodation?.status === 'open_hotel'
                            ? undefined
                            : firstFieldRef
                    }
                    linkedHotelDates={[
                        {
                            dateKey: 'check_out_date',
                            syncedKey: 'check_out_date_auto_synced',
                            active:
                                postSignoffAccommodation?.status ===
                                'open_hotel',
                        },
                    ]}
                />
            ) : null}

            {config.completionIntentOptions && config.completionIntentLabel ? (
                <MovementNextPhaseChoice
                    id="movement-completion-intent"
                    label={config.completionIntentLabel}
                    value={form.data.completion_intent}
                    options={config.completionIntentOptions}
                    onChange={(value) =>
                        form.setData(
                            'completion_intent',
                            value as 'close' | 'redeploy',
                        )
                    }
                    error={form.errors.completion_intent}
                />
            ) : null}
        </div>
    );
}
