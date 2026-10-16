import { useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import type { FormEvent, ReactElement } from 'react';
import { AppDateField } from '@/components/app-date-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { roomTypesForHotel } from '@/features/organization/crew/lib/accommodation-room-types';
import type {
    CrewAccommodationSummaryItem,
    CrewAssignmentFormOptions,
} from '@/features/organization/crew/types';
import { update as updateAccommodationStay } from '@/routes/organization/crew-assignments/accommodation-stays';

export type CrewAccommodationStayEditForm = {
    hotel_id: number | null;
    room_type_id: number | null;
    check_in_date: string;
    check_out_date: string;
    reason: string;
    expected_edit_version: number | null;
};

type SelectOption = {
    id: number;
    name: string;
    hotel_id?: number | null;
    historical?: boolean;
};

function emptyForm(): CrewAccommodationStayEditForm {
    return {
        hotel_id: null,
        room_type_id: null,
        check_in_date: '',
        check_out_date: '',
        reason: '',
        expected_edit_version: null,
    };
}

function formFromStay(
    stay: CrewAccommodationSummaryItem,
): CrewAccommodationStayEditForm {
    return {
        hotel_id: stay.hotel_id ?? null,
        room_type_id: stay.room_type_id ?? null,
        check_in_date: stay.check_in_date ?? '',
        check_out_date: stay.check_out_date ?? '',
        reason: '',
        expected_edit_version: stay.edit_version ?? 0,
    };
}

function hotelOptionsForStay(
    stay: CrewAccommodationSummaryItem | null,
    formOptions?: CrewAssignmentFormOptions,
): SelectOption[] {
    const active = (formOptions?.hotels ?? []).map((hotel) => ({
        id: hotel.id,
        name: hotel.name,
        historical: false,
    }));

    if (stay?.hotel_id == null) {
        return active;
    }

    if (active.some((hotel) => hotel.id === stay.hotel_id)) {
        return active;
    }

    return [
        {
            id: stay.hotel_id,
            name: stay.hotel_display ?? stay.hotel_name ?? 'Unavailable',
            historical: true,
        },
        ...active,
    ];
}

function roomTypeOptionsForStay(
    stay: CrewAccommodationSummaryItem | null,
    hotelId: number | null,
    formOptions?: CrewAssignmentFormOptions,
): SelectOption[] {
    const active = roomTypesForHotel(formOptions?.room_types, hotelId).map(
        (roomType) => ({
            id: roomType.id,
            name: roomType.name,
            hotel_id: roomType.hotel_id,
            historical: false,
        }),
    );

    if (
        stay == null ||
        stay.room_type_id == null ||
        hotelId == null ||
        hotelId !== (stay.hotel_id ?? null)
    ) {
        return active;
    }

    if (active.some((roomType) => roomType.id === stay.room_type_id)) {
        return active;
    }

    return [
        {
            id: stay.room_type_id,
            name:
                stay.room_type_display ?? stay.room_type_name ?? 'Unavailable',
            hotel_id: hotelId,
            historical: true,
        },
        ...active,
    ];
}

export function CrewAccommodationStayEditSheet({
    open,
    onOpenChange,
    assignmentId,
    stay,
    formOptions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignmentId: number;
    stay: CrewAccommodationSummaryItem | null;
    formOptions?: CrewAssignmentFormOptions;
}): ReactElement {
    const form = useForm<CrewAccommodationStayEditForm>(emptyForm());

    useEffect(() => {
        if (!open || stay === null) {
            return;
        }

        form.setData(formFromStay(stay));
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when the edited stay opens
    }, [open, stay?.id, stay?.edit_version, stay?.updated_at]);

    const hotelOptions = useMemo(
        () => hotelOptionsForStay(stay, formOptions),
        [stay, formOptions],
    );

    const roomTypeOptions = useMemo(
        () => roomTypeOptionsForStay(stay, form.data.hotel_id, formOptions),
        [stay, form.data.hotel_id, formOptions],
    );

    const selectedRoomTypeValue = useMemo(() => {
        if (form.data.room_type_id === null) {
            return '__none__';
        }

        if (
            roomTypeOptions.some(
                (roomType) => roomType.id === form.data.room_type_id,
            )
        ) {
            return form.data.room_type_id.toString();
        }

        // Persisted ID that cannot be listed — still show as selected historical.
        return form.data.room_type_id.toString();
    }, [form.data.room_type_id, roomTypeOptions]);

    const submit = (event: FormEvent): void => {
        event.preventDefault();

        if (stay === null || form.processing) {
            return;
        }

        if (form.data.expected_edit_version === null) {
            form.setError(
                'expected_edit_version',
                'This accommodation record version is missing. Refresh and try again.',
            );

            return;
        }

        form.transform((data) => ({
            hotel_id: data.hotel_id,
            room_type_id: data.room_type_id,
            check_in_date: data.check_in_date,
            check_out_date: data.check_out_date || null,
            reason: data.reason,
            expected_edit_version: data.expected_edit_version,
        }));

        form.put(
            updateAccommodationStay.url({
                assignment: assignmentId,
                stay: stay.id,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    form.reset();
                },
            },
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-md"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="flex items-center gap-2 text-xl font-bold tracking-tight">
                        <Pencil className="size-5" aria-hidden />
                        Edit Accommodation
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        Correct hotel stay details. Movement dates and payroll
                        are not changed.
                    </SheetDescription>
                </SheetHeader>

                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <div className="flex-1 space-y-6 overflow-y-auto p-8">
                        <div className="space-y-2">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Accommodation Type
                            </Label>
                            <div className="rounded-xl border border-border/60 bg-muted/20 px-3 py-2.5 text-sm">
                                {stay?.stay_type_label ?? '—'}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="accommodation-edit-hotel">
                                Hotel{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Select
                                value={form.data.hotel_id?.toString() ?? ''}
                                onValueChange={(value) => {
                                    const hotelId = value
                                        ? Number(value)
                                        : null;
                                    const nextRoomTypes =
                                        roomTypeOptionsForStay(
                                            stay,
                                            hotelId,
                                            formOptions,
                                        );
                                    const keepRoomType =
                                        form.data.room_type_id !== null &&
                                        nextRoomTypes.some(
                                            (roomType) =>
                                                roomType.id ===
                                                form.data.room_type_id,
                                        );

                                    form.setData({
                                        ...form.data,
                                        hotel_id: hotelId,
                                        room_type_id: keepRoomType
                                            ? form.data.room_type_id
                                            : null,
                                    });
                                }}
                            >
                                <SelectTrigger id="accommodation-edit-hotel">
                                    <SelectValue placeholder="Select hotel..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {hotelOptions.map((hotel) => (
                                        <SelectItem
                                            key={hotel.id}
                                            value={hotel.id.toString()}
                                        >
                                            {hotel.name}
                                            {hotel.historical
                                                ? ' — current historical'
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {stay?.hotel_id != null &&
                            stay.hotel_is_active === false &&
                            form.data.hotel_id === stay.hotel_id ? (
                                <p className="text-xs text-muted-foreground">
                                    Current hotel is inactive. Keep it, or
                                    choose an active hotel to correct the stay.
                                </p>
                            ) : null}
                            {stay?.hotel_unresolved &&
                            form.data.hotel_id === stay.hotel_id ? (
                                <p className="text-xs text-muted-foreground">
                                    Current hotel reference could not be
                                    resolved. Choose an active hotel to correct
                                    it.
                                </p>
                            ) : null}
                            <InputError message={form.errors.hotel_id} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="accommodation-edit-room-type">
                                Room Type
                            </Label>
                            <Select
                                value={selectedRoomTypeValue}
                                onValueChange={(value) =>
                                    form.setData(
                                        'room_type_id',
                                        value === '__none__'
                                            ? null
                                            : Number(value),
                                    )
                                }
                                disabled={form.data.hotel_id === null}
                            >
                                <SelectTrigger id="accommodation-edit-room-type">
                                    <SelectValue placeholder="Not specified" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="__none__">
                                        Not specified
                                    </SelectItem>
                                    {roomTypeOptions.map((roomType) => (
                                        <SelectItem
                                            key={roomType.id}
                                            value={roomType.id.toString()}
                                        >
                                            {roomType.name}
                                            {roomType.historical
                                                ? ' — current historical'
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {stay?.room_type_id != null &&
                            stay.room_type_is_active === false &&
                            form.data.room_type_id === stay.room_type_id ? (
                                <p className="text-xs text-muted-foreground">
                                    Current room type is inactive or legacy.
                                    Keep it, clear it, or choose an active room
                                    type for this hotel.
                                </p>
                            ) : null}
                            {stay?.room_type_unresolved &&
                            form.data.room_type_id === stay.room_type_id ? (
                                <p className="text-xs text-muted-foreground">
                                    Current room type reference could not be
                                    resolved. Clear it or choose an active room
                                    type.
                                </p>
                            ) : null}
                            <InputError message={form.errors.room_type_id} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="accommodation-edit-check-in">
                                Check-in Date{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <AppDateField
                                id="accommodation-edit-check-in"
                                value={form.data.check_in_date}
                                onValueChange={(value) =>
                                    form.setData('check_in_date', value)
                                }
                            />
                            <InputError message={form.errors.check_in_date} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="accommodation-edit-check-out">
                                Check-out Date
                            </Label>
                            <AppDateField
                                id="accommodation-edit-check-out"
                                value={form.data.check_out_date}
                                onValueChange={(value) =>
                                    form.setData('check_out_date', value)
                                }
                                clearable
                            />
                            <p className="text-xs text-muted-foreground">
                                Leave blank only when an open stay is valid.
                            </p>
                            <InputError message={form.errors.check_out_date} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="accommodation-edit-reason">
                                Reason for Edit{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Textarea
                                id="accommodation-edit-reason"
                                value={form.data.reason}
                                onChange={(event) =>
                                    form.setData('reason', event.target.value)
                                }
                                rows={4}
                                placeholder="Explain why this accommodation record is being corrected."
                            />
                            <InputError message={form.errors.reason} />
                            <InputError
                                message={form.errors.expected_edit_version}
                            />
                            <InputError
                                message={
                                    (
                                        form.errors as Record<
                                            string,
                                            string | undefined
                                        >
                                    ).accommodation
                                }
                            />
                        </div>
                    </div>

                    <SheetFooter className="border-t border-border/60 p-6">
                        <div className="flex w-full gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                className="flex-1"
                                disabled={form.processing}
                                onClick={() => onOpenChange(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="flex-1"
                                disabled={form.processing}
                            >
                                {form.processing ? 'Saving...' : 'Save Changes'}
                            </Button>
                        </div>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
