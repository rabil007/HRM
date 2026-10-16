import type { ReactElement } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { roomTypesForHotel } from '@/features/organization/crew/lib/accommodation-room-types';
import {
    setHotelDateLink,
    setManualHotelDate,
} from '@/features/organization/crew/lib/linked-hotel-date';
import { MovementNextPhaseChoice } from '../movement-next-phase-choice';
import { LinkedHotelDateField } from './linked-hotel-date-field';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function RecordArrivalForm({
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
    schedulingMode,
    allowsFutureDates,
    preventPastSelection,
}: MovementActionFormProps): ReactElement {
    const isFixedArrivalPhase =
        context.current_phase_code === 'p0' ||
        context.current_phase_code === 'p1';
    const noHotelAccommodation = form.data.no_hotel_accommodation;
    const availableRoomTypes = roomTypesForHotel(
        formOptions?.room_types,
        form.data.hotel_id,
    );
    const checkInLinked = form.data.check_in_date_auto_synced === true;

    const setNoHotelAccommodation = (checked: boolean): void => {
        form.setData((current) => ({
            ...current,
            no_hotel_accommodation: checked,
            accommodation_status: checked ? 'no_accommodation' : 'hotel',
            hotel_id: checked ? null : current.hotel_id,
            room_type_id: checked ? null : current.room_type_id,
            check_in_date: checked ? '' : current.occurred_at.slice(0, 10),
            check_in_date_auto_synced: !checked,
        }));
    };

    return (
        <div className="space-y-4">
            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    timezone={context.company_timezone}
                    schedulingMode={Boolean(schedulingMode)}
                    allowsFutureDates={Boolean(allowsFutureDates)}
                    preventPastSelection={Boolean(preventPastSelection)}
                    inputRef={firstFieldRef}
                    linkedHotelDates={[
                        {
                            dateKey: 'check_in_date',
                            syncedKey: 'check_in_date_auto_synced',
                            active: !noHotelAccommodation,
                        },
                    ]}
                />
            ) : null}

            <div className="space-y-4 rounded-lg border border-border/60 p-4">
                <div>
                    <h3 className="text-sm font-semibold">Accommodation</h3>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Record the crew member&apos;s pre-join hotel stay, or
                        mark that no hotel accommodation is required.
                    </p>
                </div>

                {!noHotelAccommodation ? (
                    <>
                        <div className="space-y-2">
                            <Label htmlFor="movement-hotel">
                                Hotel{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Select
                                value={form.data.hotel_id?.toString() ?? ''}
                                onValueChange={(value) =>
                                    form.setData((current) => ({
                                        ...current,
                                        hotel_id: value ? Number(value) : null,
                                        room_type_id: null,
                                    }))
                                }
                            >
                                <SelectTrigger id="movement-hotel">
                                    <SelectValue placeholder="Select hotel..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {(formOptions?.hotels ?? []).map(
                                        (hotel) => (
                                            <SelectItem
                                                key={hotel.id}
                                                value={hotel.id.toString()}
                                            >
                                                {hotel.name}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.hotel_id} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="movement-room-type">
                                Room Type
                            </Label>
                            <Select
                                value={
                                    form.data.room_type_id?.toString() ??
                                    '__none__'
                                }
                                onValueChange={(value) =>
                                    form.setData(
                                        'room_type_id',
                                        value === '__none__'
                                            ? null
                                            : Number(value),
                                    )
                                }
                            >
                                <SelectTrigger id="movement-room-type">
                                    <SelectValue placeholder="Select room type..." />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">
                                        Not assigned yet
                                    </SelectItem>
                                    {availableRoomTypes.map((roomType) => (
                                        <SelectItem
                                            key={roomType.id}
                                            value={roomType.id.toString()}
                                        >
                                            {roomType.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.room_type_id} />
                        </div>

                        <LinkedHotelDateField
                            id="movement-check-in-date"
                            label="Hotel Check-In"
                            checkboxLabel="Use Arrival Date for Hotel Check-In"
                            value={form.data.check_in_date}
                            linked={checkInLinked}
                            error={form.errors.check_in_date}
                            onLinkedChange={(linked) =>
                                form.setData((current) =>
                                    setHotelDateLink(
                                        current,
                                        'check_in_date',
                                        'check_in_date_auto_synced',
                                        linked,
                                    ),
                                )
                            }
                            onValueChange={(value) =>
                                form.setData((current) =>
                                    setManualHotelDate(
                                        current,
                                        'check_in_date',
                                        'check_in_date_auto_synced',
                                        value,
                                    ),
                                )
                            }
                        />
                    </>
                ) : null}

                <div className="flex items-start gap-3">
                    <Checkbox
                        id="movement-no-hotel-accommodation"
                        checked={noHotelAccommodation}
                        onCheckedChange={(checked) =>
                            setNoHotelAccommodation(checked === true)
                        }
                    />
                    <div className="space-y-1">
                        <Label
                            htmlFor="movement-no-hotel-accommodation"
                            className="font-normal"
                        >
                            No hotel accommodation
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            Use this only when the crew member will not stay in
                            a hotel before joining the vessel.
                        </p>
                    </div>
                </div>
                <InputError message={form.errors.accommodation_status} />
            </div>

            {isFixedArrivalPhase ? (
                <div className="rounded-md border border-border/60 bg-muted/40 p-3 text-sm text-muted-foreground">
                    This records the crew member&apos;s actual arrival and
                    starts Pre-Joining Standby.
                </div>
            ) : config.nextPhaseOptions && config.nextPhaseLabel ? (
                <MovementNextPhaseChoice
                    id="movement-next-phase"
                    label={config.nextPhaseLabel}
                    value={form.data.next_phase}
                    options={config.nextPhaseOptions}
                    onChange={(value) => form.setData('next_phase', value)}
                    error={form.errors.next_phase}
                />
            ) : null}
        </div>
    );
}
