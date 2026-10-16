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
    movementCalendarDate,
    setHotelDateLink,
    setManualHotelDate,
} from '@/features/organization/crew/lib/linked-hotel-date';
import { formatDisplayDate } from '@/lib/format-date';
import { formatDaysOnboard } from '../../format-days-in-phase';
import { MovementNextPhaseChoice } from '../movement-next-phase-choice';
import { LinkedHotelDateField } from './linked-hotel-date-field';
import { MovementOccurredAtField } from './movement-form-shared';
import type { MovementActionFormProps } from './movement-form-shared';

export function ConfirmDisembarkationForm({
    form,
    config,
    context,
    formOptions,
    firstFieldRef,
    schedulingMode,
    allowsFutureDates,
    preventPastSelection,
}: MovementActionFormProps): ReactElement {
    const disembarkDate = form.data.occurred_at.slice(0, 10);
    const beforeJoin =
        context.actual_join_at &&
        disembarkDate &&
        disembarkDate < context.actual_join_at;
    const showPostSignoffAccommodation = form.data.next_phase === 'p3';
    const noHotelAccommodation = form.data.no_hotel_accommodation;
    const availableRoomTypes = roomTypesForHotel(
        formOptions?.room_types,
        form.data.hotel_id,
    );
    const checkInLinked = form.data.check_in_date_auto_synced === true;

    const setNextPhase = (value: string): void => {
        form.setData((current) => {
            if (value === 'p4') {
                return {
                    ...current,
                    next_phase: value,
                    accommodation_status: '',
                    hotel_id: null,
                    room_type_id: null,
                    check_in_date: '',
                    check_in_date_auto_synced: false,
                    no_hotel_accommodation: false,
                };
            }

            const useHotel = !current.no_hotel_accommodation;

            return {
                ...current,
                next_phase: value,
                accommodation_status: useHotel ? 'hotel' : 'no_accommodation',
                check_in_date: useHotel
                    ? movementCalendarDate(current.occurred_at)
                    : '',
                check_in_date_auto_synced: useHotel,
            };
        });
    };

    const setNoHotelAccommodation = (checked: boolean): void => {
        form.setData((current) => ({
            ...current,
            no_hotel_accommodation: checked,
            accommodation_status: checked ? 'no_accommodation' : 'hotel',
            hotel_id: checked ? null : current.hotel_id,
            room_type_id: checked ? null : current.room_type_id,
            check_in_date: checked
                ? ''
                : movementCalendarDate(current.occurred_at),
            check_in_date_auto_synced: !checked,
        }));
    };

    return (
        <div className="space-y-4">
            <div className="space-y-1 rounded-lg border bg-muted/20 p-3 text-sm">
                <div>
                    <span className="text-muted-foreground">Vessel: </span>
                    <span className="font-medium">
                        {context.vessel_name ?? 'Not set'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">Position: </span>
                    <span className="font-medium">
                        {context.position_name ?? 'Not set'}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">Actual join: </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.actual_join_at)}
                    </span>
                </div>
                <div>
                    <span className="text-muted-foreground">
                        Planned sign-off:{' '}
                    </span>
                    <span className="font-medium">
                        {formatDisplayDate(context.planned_signoff_at)}
                    </span>
                </div>
                {context.days_onboard !== null ? (
                    <div>
                        <span className="text-muted-foreground">
                            Elapsed days onboard:{' '}
                        </span>
                        <span className="font-medium">
                            {formatDaysOnboard(context.days_onboard)}
                        </span>
                    </div>
                ) : null}
            </div>

            {config.occurredAtLabel ? (
                <MovementOccurredAtField
                    form={form}
                    label={config.occurredAtLabel}
                    timezone={context.company_timezone}
                    schedulingMode={Boolean(schedulingMode)}
                    allowsFutureDates={Boolean(allowsFutureDates)}
                    preventPastSelection={Boolean(preventPastSelection)}
                    inputRef={firstFieldRef}
                    min={
                        context.actual_join_at
                            ? `${context.actual_join_at}T00:00`
                            : undefined
                    }
                    linkedHotelDates={[
                        {
                            dateKey: 'check_in_date',
                            syncedKey: 'check_in_date_auto_synced',
                            active:
                                showPostSignoffAccommodation &&
                                !noHotelAccommodation,
                        },
                    ]}
                />
            ) : null}

            {beforeJoin ? (
                <p className="text-sm text-destructive">
                    The actual disembarkation cannot be before the employee
                    joined the vessel.
                </p>
            ) : null}

            {config.nextPhaseOptions && config.nextPhaseLabel ? (
                <MovementNextPhaseChoice
                    id="movement-next-phase"
                    label={config.nextPhaseLabel}
                    value={form.data.next_phase}
                    options={config.nextPhaseOptions}
                    onChange={setNextPhase}
                    error={form.errors.next_phase}
                />
            ) : null}

            {showPostSignoffAccommodation ? (
                <div className="space-y-4 rounded-lg border border-border/60 p-4">
                    <div>
                        <h3 className="text-sm font-semibold">
                            Demobilisation Standby Accommodation
                        </h3>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Record the crew member&apos;s post-sign-off hotel
                            stay, or mark that no hotel accommodation is
                            required.
                        </p>
                    </div>

                    {!noHotelAccommodation ? (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="movement-post-signoff-hotel">
                                    Hotel{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Select
                                    value={form.data.hotel_id?.toString() ?? ''}
                                    onValueChange={(value) =>
                                        form.setData((current) => ({
                                            ...current,
                                            hotel_id: value
                                                ? Number(value)
                                                : null,
                                            room_type_id: null,
                                        }))
                                    }
                                >
                                    <SelectTrigger id="movement-post-signoff-hotel">
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
                                <Label htmlFor="movement-post-signoff-room-type">
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
                                    <SelectTrigger id="movement-post-signoff-room-type">
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
                                <InputError
                                    message={form.errors.room_type_id}
                                />
                            </div>

                            <LinkedHotelDateField
                                id="movement-post-signoff-check-in-date"
                                label="Hotel Check-In"
                                checkboxLabel="Use Disembarkation Date for Hotel Check-In"
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
                            id="movement-post-signoff-no-hotel"
                            checked={noHotelAccommodation}
                            onCheckedChange={(checked) =>
                                setNoHotelAccommodation(checked === true)
                            }
                        />
                        <div className="space-y-1">
                            <Label
                                htmlFor="movement-post-signoff-no-hotel"
                                className="font-normal"
                            >
                                No hotel accommodation
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Use this only when the crew member will not stay
                                in a hotel after disembarkation.
                            </p>
                        </div>
                    </div>
                    <InputError message={form.errors.accommodation_status} />
                </div>
            ) : null}
        </div>
    );
}
