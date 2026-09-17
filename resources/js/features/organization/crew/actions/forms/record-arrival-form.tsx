import type { ReactElement } from 'react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { roomTypesForHotel } from '@/features/organization/crew/lib/accommodation-room-types';
import { MovementNextPhaseChoice } from '../movement-next-phase-choice';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function RecordArrivalForm({
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
}: MovementActionFormProps): ReactElement {
    const isFixedArrivalPhase =
        context.current_phase_code === 'p0' ||
        context.current_phase_code === 'p1';
    const noHotelAccommodation = form.data.no_hotel_accommodation;
    const lastAutoCheckInDateRef = useRef(
        form.data.check_in_date || form.data.occurred_at.slice(0, 10),
    );
    const availableRoomTypes = roomTypesForHotel(
        formOptions?.room_types,
        form.data.hotel_id,
    );

    const syncCheckInDate = (occurredAt: string): void => {
        if (noHotelAccommodation) {
            return;
        }

        const arrivalDate = occurredAt.slice(0, 10);

        if (
            !arrivalDate ||
            (form.data.check_in_date !== '' &&
                form.data.check_in_date !== lastAutoCheckInDateRef.current)
        ) {
            return;
        }

        form.setData('check_in_date', arrivalDate);
        lastAutoCheckInDateRef.current = arrivalDate;
    };

    const setNoHotelAccommodation = (checked: boolean): void => {
        const nextCheckInDate = checked
            ? ''
            : form.data.occurred_at.slice(0, 10);

        if (!checked) {
            lastAutoCheckInDateRef.current = nextCheckInDate;
        }

        form.setData({
            ...form.data,
            no_hotel_accommodation: checked,
            accommodation_status: checked ? 'no_accommodation' : 'hotel',
            hotel_id: checked ? null : form.data.hotel_id,
            room_type_id: checked ? null : form.data.room_type_id,
            check_in_date: nextCheckInDate,
        });
    };

    return (
        <div className="space-y-4">
            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    timezone={context.company_timezone}
                    inputRef={firstFieldRef}
                    onValueChange={syncCheckInDate}
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
                                    form.setData({
                                        ...form.data,
                                        hotel_id: value ? Number(value) : null,
                                        room_type_id: null,
                                    })
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

                        <div className="space-y-2">
                            <Label htmlFor="movement-check-in-date">
                                Check-in Date{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="movement-check-in-date"
                                type="date"
                                value={form.data.check_in_date}
                                onChange={(event) =>
                                    form.setData(
                                        'check_in_date',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.check_in_date} />
                        </div>
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
                    starts Join Standby.
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
