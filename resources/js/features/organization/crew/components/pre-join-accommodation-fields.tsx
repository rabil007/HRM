import type { ReactElement } from 'react';
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
import type { PreJoinAccommodationFields } from '@/features/organization/crew/lib/pre-join-accommodation';
import type { CrewAssignmentFormOptions } from '@/features/organization/crew/types';

type PreJoinAccommodationFieldsProps = {
    value: PreJoinAccommodationFields;
    onChange: (value: PreJoinAccommodationFields) => void;
    formOptions: CrewAssignmentFormOptions;
    errors: Record<string, string | undefined>;
    errorPrefix?: string;
    arrivalDate?: string | null;
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
    compact = false,
}: PreJoinAccommodationFieldsProps): ReactElement {
    const noHotelAccommodation =
        value.accommodation_status === 'no_accommodation';
    const availableRoomTypes = roomTypesForHotel(
        formOptions.room_types,
        value.hotel_id,
    );

    const setNoHotelAccommodation = (checked: boolean): void => {
        onChange({
            ...value,
            accommodation_status: checked ? 'no_accommodation' : 'hotel',
            hotel_id: checked ? null : value.hotel_id,
            room_type_id: checked ? null : value.room_type_id,
            check_in_date: checked
                ? ''
                : arrivalDate?.slice(0, 10) || value.check_in_date,
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
                    Pre-Join Accommodation
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

                    <div className="space-y-2">
                        <Label htmlFor={`${errorPrefix ?? 'start'}-check-in`}>
                            Actual Hotel Check-In Date{' '}
                            <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id={`${errorPrefix ?? 'start'}-check-in`}
                            type="date"
                            value={value.check_in_date}
                            onChange={(event) =>
                                onChange({
                                    ...value,
                                    check_in_date: event.target.value,
                                })
                            }
                        />
                        <InputError
                            message={fieldError(
                                errors,
                                errorPrefix,
                                'check_in_date',
                            )}
                        />
                    </div>
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
