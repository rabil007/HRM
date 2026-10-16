import { useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useEffect } from 'react';
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
    expected_updated_at: string | null;
};

function emptyForm(): CrewAccommodationStayEditForm {
    return {
        hotel_id: null,
        room_type_id: null,
        check_in_date: '',
        check_out_date: '',
        reason: '',
        expected_updated_at: null,
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
        expected_updated_at: stay.updated_at ?? null,
    };
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
    }, [open, stay?.id, stay?.updated_at]);

    const availableRoomTypes = roomTypesForHotel(
        formOptions?.room_types,
        form.data.hotel_id,
    );

    const selectedRoomTypeStillValid =
        form.data.room_type_id === null ||
        availableRoomTypes.some(
            (roomType) => roomType.id === form.data.room_type_id,
        );

    const submit = (event: FormEvent): void => {
        event.preventDefault();

        if (stay === null || form.processing) {
            return;
        }

        form.transform((data) => ({
            hotel_id: data.hotel_id,
            room_type_id: data.room_type_id,
            check_in_date: data.check_in_date,
            check_out_date: data.check_out_date || null,
            reason: data.reason,
            expected_updated_at: data.expected_updated_at,
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
                                    const nextRoomTypes = roomTypesForHotel(
                                        formOptions?.room_types,
                                        hotelId,
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
                            <Label htmlFor="accommodation-edit-room-type">
                                Room Type
                            </Label>
                            <Select
                                value={
                                    form.data.room_type_id !== null &&
                                    selectedRoomTypeStillValid
                                        ? form.data.room_type_id.toString()
                                        : '__none__'
                                }
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
                                message={form.errors.expected_updated_at}
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
