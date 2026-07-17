import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    CrewMovementHistoryFilters,
    CrewMovementHistoryProps,
    ReportOption,
    SelectOption,
} from './types';

type FilterOptions = CrewMovementHistoryProps['filter_options'];

function SelectFilter({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: Array<ReportOption | SelectOption>;
    onChange: (value: string) => void;
}) {
    return (
        <div className="space-y-2">
            <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                {label}
            </Label>
            <AppSelect
                value={value}
                onValueChange={onChange}
                variant="dark"
                placeholder={`All ${label.toLowerCase()}`}
                searchPlaceholder={`Search ${label.toLowerCase()}...`}
            >
                <AppSelectItem value="">
                    All {label.toLowerCase()}
                </AppSelectItem>
                {options.map((option) => {
                    const optionValue =
                        'value' in option ? option.value : String(option.id);
                    const optionLabel =
                        'label' in option ? option.label : option.name;

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

export function CrewMovementHistoryFiltersSheet({
    open,
    onOpenChange,
    filters,
    options,
    onApply,
    onClear,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: CrewMovementHistoryFilters;
    options: FilterOptions;
    onApply: (filters: CrewMovementHistoryFilters) => void;
    onClear: () => void;
}) {
    const [draft, setDraft] = useState(filters);

    const close = (nextOpen: boolean): void => {
        if (!nextOpen && open) {
            onApply(draft);
        }

        onOpenChange(nextOpen);
    };

    const set = (key: keyof CrewMovementHistoryFilters, value: string): void =>
        setDraft((current) => ({ ...current, [key]: value }));

    return (
        <FiltersSheet
            open={open}
            onOpenChange={close}
            title="Crew movement filters"
            resetText="Clear Filters"
            onReset={() => {
                onOpenChange(false);
                onClear();
            }}
        >
            <SelectFilter
                label="Status"
                value={draft.status}
                options={options.statuses}
                onChange={(value) => set('status', value)}
            />
            <SelectFilter
                label="Current phase"
                value={draft.current_phase}
                options={options.phases}
                onChange={(value) => set('current_phase', value)}
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
                label="Visa type / sponsor"
                value={draft.visa_type_id}
                options={options.visa_types}
                onChange={(value) => set('visa_type_id', value)}
            />
            <SelectFilter
                label="Source"
                value={draft.source}
                options={options.sources}
                onChange={(value) => set('source', value)}
            />
            <SelectFilter
                label="Needs attention"
                value={draft.needs_attention}
                options={[{ value: '1', label: 'Needs attention' }]}
                onChange={(value) => set('needs_attention', value)}
            />
            <SelectFilter
                label="Approved corrections"
                value={draft.has_approved_corrections}
                options={[{ value: '1', label: 'Has approved corrections' }]}
                onChange={(value) => set('has_approved_corrections', value)}
            />
            <SelectFilter
                label="Pending corrections"
                value={draft.has_pending_corrections}
                options={[{ value: '1', label: 'Has pending corrections' }]}
                onChange={(value) => set('has_pending_corrections', value)}
            />
            <DateRange
                label="Planned join"
                from={draft.planned_join_from}
                to={draft.planned_join_to}
                onFromChange={(value) => set('planned_join_from', value)}
                onToChange={(value) => set('planned_join_to', value)}
            />
            <DateRange
                label="Actual join"
                from={draft.actual_join_from}
                to={draft.actual_join_to}
                onFromChange={(value) => set('actual_join_from', value)}
                onToChange={(value) => set('actual_join_to', value)}
            />
            <DateRange
                label="Actual disembarkation"
                from={draft.actual_disembarkation_from}
                to={draft.actual_disembarkation_to}
                onFromChange={(value) =>
                    set('actual_disembarkation_from', value)
                }
                onToChange={(value) => set('actual_disembarkation_to', value)}
            />
            <DateRange
                label="Assignment started"
                from={draft.assignment_started_from}
                to={draft.assignment_started_to}
                onFromChange={(value) => set('assignment_started_from', value)}
                onToChange={(value) => set('assignment_started_to', value)}
            />
            <DateRange
                label="Assignment closed"
                from={draft.assignment_closed_from}
                to={draft.assignment_closed_to}
                onFromChange={(value) => set('assignment_closed_from', value)}
                onToChange={(value) => set('assignment_closed_to', value)}
            />
        </FiltersSheet>
    );
}
