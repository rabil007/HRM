import type { ReactElement } from 'react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDisplayDate } from '@/lib/format-date';
import { MovementNextPhaseChoice } from '../movement-next-phase-choice';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function TravelHomeForm({
    form,
    config,
    context,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const postSignoffAccommodation = context.post_signoff_accommodation;

    const lastAutoCheckOutDateRef = useRef(
        form.data.check_out_date || form.data.occurred_at.slice(0, 10),
    );

    const syncCheckOutDate = (occurredAt: string): void => {
        if (postSignoffAccommodation?.status !== 'open_hotel') {
            return;
        }

        const nextReturnHomeDate = occurredAt.slice(0, 10);

        if (
            !nextReturnHomeDate ||
            (form.data.check_out_date !== '' &&
                form.data.check_out_date !== lastAutoCheckOutDateRef.current)
        ) {
            return;
        }

        form.setData('check_out_date', nextReturnHomeDate);
        lastAutoCheckOutDateRef.current = nextReturnHomeDate;
    };

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
                                Stay {postSignoffAccommodation.stay_days} day
                                {postSignoffAccommodation.stay_days === 1
                                    ? ''
                                    : 's'}
                            </div>
                        ) : null}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="movement-post-signoff-check-out-date">
                            Hotel Check-out Date{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="movement-post-signoff-check-out-date"
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
                    inputRef={
                        postSignoffAccommodation?.status === 'open_hotel'
                            ? undefined
                            : firstFieldRef
                    }
                    onValueChange={syncCheckOutDate}
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
