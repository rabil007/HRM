import { useEffect, useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    HotelCheckInCheckoutFilterOptions,
    HotelCheckInCheckoutFilters,
} from './types';

function SelectFilter({
    label,
    value,
    options,
    placeholder,
    onChange,
}: {
    label: string;
    value: string;
    options: Array<{
        id?: number;
        value?: string;
        name?: string;
        label?: string;
    }>;
    placeholder?: string;
    onChange: (value: string) => void;
}) {
    const defaultPlaceholder = placeholder ?? `All ${label.toLowerCase()}`;

    return (
        <div className="space-y-2">
            <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                {label}
            </Label>
            <AppSelect
                value={value}
                onValueChange={onChange}
                variant="dark"
                placeholder={defaultPlaceholder}
                searchPlaceholder={`Search ${label.toLowerCase()}...`}
            >
                <AppSelectItem value="">{defaultPlaceholder}</AppSelectItem>
                {options.map((option) => {
                    const optionValue =
                        option.value !== undefined
                            ? option.value
                            : String(option.id);
                    const optionLabel =
                        option.label !== undefined
                            ? option.label
                            : (option.name ?? '');

                    return (
                        <AppSelectItem key={optionValue} value={optionValue}>
                            {optionLabel}
                        </AppSelectItem>
                    );
                })}
            </AppSelect>
        </div>
    );
}

function DateRange({
    label,
    from,
    to,
    onFromChange,
    onToChange,
}: {
    label: string;
    from: string;
    to: string;
    onFromChange: (value: string) => void;
    onToChange: (value: string) => void;
}) {
    const id = label.toLowerCase().replaceAll(' ', '-');

    return (
        <div className="space-y-2">
            <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                {label}
            </Label>
            <div className="grid grid-cols-2 gap-2">
                <Input
                    id={`${id}-from`}
                    type="date"
                    aria-label={`${label} from`}
                    value={from}
                    onChange={(event) => onFromChange(event.target.value)}
                />
                <Input
                    id={`${id}-to`}
                    type="date"
                    aria-label={`${label} to`}
                    value={to}
                    onChange={(event) => onToChange(event.target.value)}
                />
            </div>
        </div>
    );
}

export function HotelCheckInCheckoutFiltersSheet({
    open,
    onOpenChange,
    filters,
    options,
    onApply,
    onClear,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: HotelCheckInCheckoutFilters;
    options: HotelCheckInCheckoutFilterOptions;
    onApply: (filters: HotelCheckInCheckoutFilters) => void;
    onClear: () => void;
}) {
    const [draft, setDraft] = useState(filters);

    useEffect(() => {
        setDraft(filters);
    }, [filters]);

    const close = (nextOpen: boolean): void => {
        if (!nextOpen && open) {
            onApply(draft);
        }

        onOpenChange(nextOpen);
    };

    const set = (key: keyof HotelCheckInCheckoutFilters, value: string): void =>
        setDraft((current) => ({ ...current, [key]: value }));

    const availableRoomTypes = useMemo(() => {
        if (!draft.hotel_id) {
            return options.room_types;
        }

        const hotelId = Number(draft.hotel_id);

        return options.room_types.filter(
            (roomType) =>
                roomType.hotel_id === null || roomType.hotel_id === hotelId,
        );
    }, [draft.hotel_id, options.room_types]);

    return (
        <FiltersSheet
            open={open}
            onOpenChange={close}
            title="Hotel check-in & check-out filters"
            resetText="Clear Filters"
            onReset={() => {
                onOpenChange(false);
                onClear();
            }}
        >
            <div className="space-y-4">
                <SelectFilter
                    label="Hotel"
                    value={draft.hotel_id}
                    options={options.hotels}
                    onChange={(value) => {
                        setDraft((current) => ({
                            ...current,
                            hotel_id: value,
                            room_type_id: '',
                        }));
                    }}
                />

                <SelectFilter
                    label="Room Type"
                    value={draft.room_type_id}
                    options={availableRoomTypes}
                    onChange={(value) => set('room_type_id', value)}
                />

                <SelectFilter
                    label="Stay Type"
                    value={draft.stay_type}
                    options={options.stay_types}
                    onChange={(value) => set('stay_type', value)}
                />

                <SelectFilter
                    label="Stay Status"
                    value={draft.stay_status}
                    options={options.stay_statuses}
                    onChange={(value) => set('stay_status', value)}
                />

                <DateRange
                    label="Check-In Date"
                    from={draft.check_in_from}
                    to={draft.check_in_to}
                    onFromChange={(value) => set('check_in_from', value)}
                    onToChange={(value) => set('check_in_to', value)}
                />

                <DateRange
                    label="Check-Out Date"
                    from={draft.check_out_from}
                    to={draft.check_out_to}
                    onFromChange={(value) => set('check_out_from', value)}
                    onToChange={(value) => set('check_out_to', value)}
                />

                <SelectFilter
                    label="Vessel"
                    value={draft.vessel_id}
                    options={options.vessels}
                    onChange={(value) => set('vessel_id', value)}
                />

                <SelectFilter
                    label="Rank"
                    value={draft.rank_id}
                    options={options.ranks}
                    onChange={(value) => set('rank_id', value)}
                />

                <SelectFilter
                    label="Client"
                    value={draft.client_id}
                    options={options.clients}
                    onChange={(value) => set('client_id', value)}
                />

                <SelectFilter
                    label="Accommodation Status"
                    value={draft.accommodation_status}
                    options={options.accommodation_statuses}
                    onChange={(value) => set('accommodation_status', value)}
                />
            </div>
        </FiltersSheet>
    );
}
