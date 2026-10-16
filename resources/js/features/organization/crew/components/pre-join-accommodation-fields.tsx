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
import { LinkedHotelDateField } from '@/features/organization/crew/actions/forms/linked-hotel-date-field';
import { roomTypesForHotel } from '@/features/organization/crew/lib/accommodation-room-types';
import type { PreJoinAccommodationFields } from '@/features/organization/crew/lib/pre-join-accommodation';
import type { CrewAssignmentFormOptions } from '@/features/organization/crew/types';

type PreJoinAccommodationFieldsProps = {
    value: PreJoinAccommodationFields;
    onChange: (value: PreJoinAccommodationFields) => void;
    formOptions: CrewAssignmentFormOptions;
    errors: Record<string, string | undefined>;
    errorPrefix?: string;
    arrivalDate?: string | null;
    sameDateLabel?: string;
    compact?: boolean;
};

function fieldError(
    errors: Record<string, string | undefined>,
    prefix: string | undefined,
    field: string,
): string | undefined {
    const key = prefix ? `${prefix}.${field}` : field;

    return errors[key];
}

export function PreJoinAccommodationFields({
    value,
    onChange,
    formOptions,
    errors,
    errorPrefix,
    arrivalDate,
    sameDateLabel = 'Use Arrival Date for Hotel Check-In',
    compact = false,
}: PreJoinAccommodationFieldsProps): ReactElement {
    const noHotelAccommodation =
        value.accommodation_status === 'no_accommodation';
    const availableRoomTypes = roomTypesForHotel(
        formOptions.room_types,
        value.hotel_id,
    );

    const checkInLinked = value.check_in_date_auto_synced !== false;

    const setNoHotelAccommodation = (checked: boolean): void => {
        onChange({
            ...value,
            accommodation_status: checked ? 'no_accommodation' : 'hotel',
            hotel_id: checked ? null : value.hotel_id,
            room_type_id: checked ? null : value.room_type_id,
            check_in_date: checked ? '' : arrivalDate?.slice(0, 10) || '',
            check_in_date_auto_synced: !checked,
        });
    };

    return (
        <div
            className={
                compact
                    ? 'space-y-3 rounded-lg border border-border/60 bg-muted/10 p-3'
                    : 'space-y-4 rounded-lg border border-border/60 p-4'
            }
        >
            <div>
                <h3 className="text-sm font-semibold">
                    Pre-Joining Standby Accommodation
                </h3>
                <p className="mt-1 text-xs text-muted-foreground">
                    Record the crew member&apos;s pre-join hotel stay, or mark
                    that no hotel accommodation is required.
                </p>
            </div>

            {!noHotelAccommodation ? (
                <>
                    <div className="space-y-2">
                        <Label htmlFor={`${errorPrefix ?? 'start'}-hotel`}>
                            Hotel <span className="text-destructive">*</span>
                        </Label>
                        <Select
                            value={value.hotel_id?.toString() ?? ''}
                            onValueChange={(hotelValue) =>
                                onChange({
                                    ...value,
                                    accommodation_status: 'hotel',
                                    hotel_id: hotelValue
                                        ? Number(hotelValue)
                                        : null,
                                    room_type_id: null,
                                })
                            }
                        >
                            <SelectTrigger
                                id={`${errorPrefix ?? 'start'}-hotel`}
                            >
                                <SelectValue placeholder="Select hotel..." />
                            </SelectTrigger>
                            <SelectContent>
                                {(formOptions.hotels ?? []).map((hotel) => (
                                    <SelectItem
                                        key={hotel.id}
                                        value={hotel.id.toString()}
                                    >
                                        {hotel.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            message={fieldError(
                                errors,
                                errorPrefix,
                                'hotel_id',
                            )}
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor={`${errorPrefix ?? 'start'}-room-type`}>
                            Room Type
                        </Label>
                        <Select
                            value={value.room_type_id?.toString() ?? '__none__'}
                            onValueChange={(roomValue) =>
                                onChange({
                                    ...value,
                                    room_type_id:
                                        roomValue === '__none__'
                                            ? null
                                            : Number(roomValue),
                                })
                            }
                        >
                            <SelectTrigger
                                id={`${errorPrefix ?? 'start'}-room-type`}
                            >
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
                            message={fieldError(
                                errors,
                                errorPrefix,
                                'room_type_id',
                            )}
                        />
                    </div>

                    <LinkedHotelDateField
                        id={`${errorPrefix ?? 'start'}-check-in`}
                        label="Hotel Check-In"
                        checkboxLabel={sameDateLabel}
                        value={value.check_in_date}
                        linked={checkInLinked}
                        error={fieldError(errors, errorPrefix, 'check_in_date')}
                        onLinkedChange={(linked) =>
                            onChange({
                                ...value,
                                check_in_date_auto_synced: linked,
                                check_in_date: linked
                                    ? (arrivalDate?.slice(0, 10) ?? '')
                                    : value.check_in_date,
                            })
                        }
                        onValueChange={(nextDate) =>
                            onChange({
                                ...value,
                                check_in_date: nextDate,
                                check_in_date_auto_synced: false,
                            })
                        }
                    />
                </>
            ) : null}

            <div className="flex items-start gap-3">
                <Checkbox
                    id={`${errorPrefix ?? 'start'}-no-hotel`}
                    checked={noHotelAccommodation}
                    onCheckedChange={(checked) =>
                        setNoHotelAccommodation(checked === true)
                    }
                />
                <div className="space-y-1">
                    <Label
                        htmlFor={`${errorPrefix ?? 'start'}-no-hotel`}
                        className="font-normal"
                    >
                        No hotel accommodation
                    </Label>
                    <p className="text-xs text-muted-foreground">
                        Use this only when the crew member will not stay in a
                        hotel before joining the vessel.
                    </p>
                </div>
            </div>
            <InputError
                message={fieldError(
                    errors,
                    errorPrefix,
                    'accommodation_status',
                )}
            />
        </div>
    );
}
