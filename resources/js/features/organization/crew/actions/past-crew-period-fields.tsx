import type { InertiaFormProps } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { inclusivePeriodDays } from '../lib/past-crew-period-days';
import type {
    HistoricalAccommodationChoice,
    HistoricalCrewAssignmentFormData,
    HistoricalFormOptions,
} from '../types';

function PeriodSection({
    title,
    fromId,
    toId,
    fromValue,
    toValue,
    fromError,
    toError,
    onFromChange,
    onToChange,
    children,
}: {
    title: string;
    fromId: string;
    toId: string;
    fromValue: string;
    toValue: string;
    fromError?: string;
    toError?: string;
    onFromChange: (value: string) => void;
    onToChange: (value: string) => void;
    children?: ReactElement | null;
}): ReactElement {
    const days = inclusivePeriodDays(fromValue, toValue);

    return (
        <div className="space-y-3 rounded-xl border border-border/80 bg-muted/20 p-4">
            <div className="text-sm font-semibold text-foreground">{title}</div>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div className="space-y-1.5">
                    <Label htmlFor={fromId}>From</Label>
                    <Input
                        id={fromId}
                        type="date"
                        value={fromValue}
                        onChange={(e) => onFromChange(e.target.value)}
                    />
                    <InputError message={fromError} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor={toId}>To</Label>
                    <Input
                        id={toId}
                        type="date"
                        value={toValue}
                        onChange={(e) => onToChange(e.target.value)}
                    />
                    <InputError message={toError} />
                </div>
                <div className="space-y-1.5">
                    <Label>Days</Label>
                    <Input value={days} readOnly placeholder="—" />
                </div>
            </div>
            {children}
        </div>
    );
}

function AccommodationFields({
    prefix,
    form,
    formOptions,
    fromDate,
    toDate,
}: {
    prefix: 'sign_on' | 'sign_off';
    form: InertiaFormProps<HistoricalCrewAssignmentFormData>;
    formOptions: HistoricalFormOptions;
    fromDate: string;
    toDate: string;
}): ReactElement {
    const choiceKey = `${prefix}_accommodation` as const;
    const hotelKey = `${prefix}_hotel_id` as const;
    const roomKey = `${prefix}_room_type_id` as const;
    const checkInKey = `${prefix}_hotel_check_in` as const;
    const checkOutKey = `${prefix}_hotel_check_out` as const;

    const choice = (form.data[choiceKey] ??
        'not_recorded') as HistoricalAccommodationChoice;
    const hotelId = form.data[hotelKey];
    const roomTypes = (formOptions.room_types ?? []).filter(
        (room) =>
            hotelId === '' ||
            hotelId == null ||
            room.hotel_id === null ||
            room.hotel_id === Number(hotelId),
    );

    const setChoice = (next: HistoricalAccommodationChoice) => {
        form.setData((prev) => ({
            ...prev,
            [choiceKey]: next,
            ...(next !== 'hotel'
                ? {
                      [hotelKey]: '',
                      [roomKey]: '',
                      [checkInKey]: '',
                      [checkOutKey]: '',
                  }
                : {
                      [checkInKey]: prev[checkInKey] || fromDate || '',
                      [checkOutKey]: prev[checkOutKey] || toDate || '',
                  }),
        }));
    };

    return (
        <div className="space-y-3 border-t border-border/60 pt-3">
            <div className="space-y-1.5">
                <Label>Accommodation</Label>
                <AppSelect
                    value={choice}
                    onValueChange={(val) =>
                        setChoice(val as HistoricalAccommodationChoice)
                    }
                    placeholder="Accommodation..."
                >
                    <AppSelectItem value="not_recorded">
                        Not recorded
                    </AppSelectItem>
                    <AppSelectItem value="no_accommodation">
                        No accommodation
                    </AppSelectItem>
                    <AppSelectItem value="hotel">Hotel</AppSelectItem>
                </AppSelect>
                <InputError message={form.errors[choiceKey]} />
            </div>

            {choice === 'hotel' ? (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label>Hotel *</Label>
                        <AppSelect
                            value={String(form.data[hotelKey] || '')}
                            onValueChange={(val) =>
                                form.setData((prev) => ({
                                    ...prev,
                                    [hotelKey]: val ? Number(val) : '',
                                    [roomKey]: '',
                                }))
                            }
                            placeholder="Select hotel..."
                            searchPlaceholder="Search hotel..."
                        >
                            {(formOptions.hotels ?? []).map((hotel) => (
                                <AppSelectItem
                                    key={hotel.id}
                                    value={String(hotel.id)}
                                >
                                    {hotel.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        <InputError message={form.errors[hotelKey]} />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Room Type</Label>
                        <AppSelect
                            value={String(form.data[roomKey] || '')}
                            onValueChange={(val) =>
                                form.setData(roomKey, val ? Number(val) : '')
                            }
                            placeholder="Optional room type..."
                            searchPlaceholder="Search room type..."
                        >
                            <AppSelectItem value="">None</AppSelectItem>
                            {roomTypes.map((room) => (
                                <AppSelectItem
                                    key={room.id}
                                    value={String(room.id)}
                                >
                                    {room.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        <InputError message={form.errors[roomKey]} />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Check-In</Label>
                        <Input
                            type="date"
                            value={String(form.data[checkInKey] || '')}
                            onChange={(e) =>
                                form.setData(checkInKey, e.target.value)
                            }
                        />
                        <InputError message={form.errors[checkInKey]} />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Check-Out</Label>
                        <Input
                            type="date"
                            value={String(form.data[checkOutKey] || '')}
                            onChange={(e) =>
                                form.setData(checkOutKey, e.target.value)
                            }
                        />
                        <InputError message={form.errors[checkOutKey]} />
                    </div>
                </div>
            ) : null}
        </div>
    );
}

export function PastCrewPeriodFields({
    form,
    formOptions,
    timezoneLabel,
}: {
    form: InertiaFormProps<HistoricalCrewAssignmentFormData>;
    formOptions: HistoricalFormOptions;
    timezoneLabel: string;
}): ReactElement {
    return (
        <div className="space-y-4">
            <div className="text-sm font-semibold text-foreground">
                Movement Periods
            </div>
            <p className="text-xs text-muted-foreground">
                Enter known periods only. Leave the current period&apos;s To
                date empty. Days are calculated automatically. Dates use company
                timezone ({timezoneLabel}).
            </p>

            <PeriodSection
                title="Sign-On Standby"
                fromId="past-sign-on-from"
                toId="past-sign-on-to"
                fromValue={form.data.sign_on_standby_from ?? ''}
                toValue={form.data.sign_on_standby_to ?? ''}
                fromError={form.errors.sign_on_standby_from}
                toError={form.errors.sign_on_standby_to}
                onFromChange={(value) =>
                    form.setData('sign_on_standby_from', value)
                }
                onToChange={(value) =>
                    form.setData('sign_on_standby_to', value)
                }
            >
                <AccommodationFields
                    prefix="sign_on"
                    form={form}
                    formOptions={formOptions}
                    fromDate={form.data.sign_on_standby_from ?? ''}
                    toDate={form.data.sign_on_standby_to ?? ''}
                />
            </PeriodSection>

            <PeriodSection
                title="Onsite / On Vessel"
                fromId="past-onsite-from"
                toId="past-onsite-to"
                fromValue={form.data.onsite_from ?? ''}
                toValue={form.data.onsite_to ?? ''}
                fromError={form.errors.onsite_from}
                toError={form.errors.onsite_to}
                onFromChange={(value) => form.setData('onsite_from', value)}
                onToChange={(value) => form.setData('onsite_to', value)}
            />

            <PeriodSection
                title="Sign-Off Standby"
                fromId="past-sign-off-from"
                toId="past-sign-off-to"
                fromValue={form.data.sign_off_standby_from ?? ''}
                toValue={form.data.sign_off_standby_to ?? ''}
                fromError={form.errors.sign_off_standby_from}
                toError={form.errors.sign_off_standby_to}
                onFromChange={(value) =>
                    form.setData('sign_off_standby_from', value)
                }
                onToChange={(value) =>
                    form.setData('sign_off_standby_to', value)
                }
            >
                <AccommodationFields
                    prefix="sign_off"
                    form={form}
                    formOptions={formOptions}
                    fromDate={form.data.sign_off_standby_from ?? ''}
                    toDate={form.data.sign_off_standby_to ?? ''}
                />
            </PeriodSection>

            <div className="space-y-3 rounded-xl border border-border/80 bg-muted/20 p-4">
                <div className="text-sm font-semibold text-foreground">
                    Home / Available From
                </div>
                <div className="max-w-xs space-y-1.5">
                    <Label htmlFor="past-home-from">Date</Label>
                    <Input
                        id="past-home-from"
                        type="date"
                        value={form.data.home_available_from ?? ''}
                        onChange={(e) =>
                            form.setData('home_available_from', e.target.value)
                        }
                    />
                    <InputError message={form.errors.home_available_from} />
                </div>
            </div>
        </div>
    );
}
