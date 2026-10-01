import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CrewReliefFilterOptions, CrewReliefFilters } from './types';

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

export function CrewReliefFiltersSheet({
    open,
    onOpenChange,
    filters,
    options,
    onApply,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: CrewReliefFilters;
    options: CrewReliefFilterOptions;
    onApply: (next: Partial<CrewReliefFilters>) => void;
    onReset: () => void;
}) {
    const [draft, setDraft] = useState<CrewReliefFilters>(filters);
    const [prevFilters, setPrevFilters] = useState<CrewReliefFilters>(filters);

    if (filters !== prevFilters) {
        setPrevFilters(filters);
        setDraft(filters);
    }

    const close = (nextOpen: boolean): void => {
        if (!nextOpen && open) {
            onApply({
                vessel_id: draft.vessel_id,
                client_id: draft.client_id,
                position_id: draft.position_id,
                planned_signoff_from: draft.planned_signoff_from,
                planned_signoff_to: draft.planned_signoff_to,
                readiness: draft.readiness,
                attention: draft.attention,
            });
        }

        onOpenChange(nextOpen);
    };

    return (
        <FiltersSheet
            open={open}
            onOpenChange={close}
            title="Relief Report Filters"
            resetText="Clear Filters"
            onReset={() => {
                onOpenChange(false);
                onReset();
            }}
        >
            <div className="space-y-4">
                <SelectFilter
                    label="Vessel"
                    value={draft.vessel_id}
                    options={options.vessels}
                    onChange={(vessel_id) =>
                        setDraft((prev) => ({ ...prev, vessel_id }))
                    }
                />

                <SelectFilter
                    label="Client"
                    value={draft.client_id}
                    options={options.clients}
                    onChange={(client_id) =>
                        setDraft((prev) => ({ ...prev, client_id }))
                    }
                />

                <SelectFilter
                    label="Position"
                    value={draft.position_id}
                    options={options.positions}
                    onChange={(position_id) =>
                        setDraft((prev) => ({ ...prev, position_id }))
                    }
                />

                <SelectFilter
                    label="Readiness"
                    value={draft.readiness}
                    options={options.readiness_options}
                    onChange={(readiness) =>
                        setDraft((prev) => ({ ...prev, readiness }))
                    }
                />

                <SelectFilter
                    label="Attention"
                    value={draft.attention}
                    options={options.attention_options}
                    onChange={(attention) =>
                        setDraft((prev) => ({ ...prev, attention }))
                    }
                />

                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        Planned Sign-Off Date Range
                    </Label>
                    <div className="grid grid-cols-2 gap-2">
                        <div className="space-y-1">
                            <Label className="text-[11px] text-muted-foreground">
                                From
                            </Label>
                            <Input
                                type="date"
                                value={draft.planned_signoff_from}
                                onChange={(e) =>
                                    setDraft((prev) => ({
                                        ...prev,
                                        planned_signoff_from: e.target.value,
                                    }))
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label className="text-[11px] text-muted-foreground">
                                To
                            </Label>
                            <Input
                                type="date"
                                value={draft.planned_signoff_to}
                                onChange={(e) =>
                                    setDraft((prev) => ({
                                        ...prev,
                                        planned_signoff_to: e.target.value,
                                    }))
                                }
                            />
                        </div>
                    </div>
                </div>
            </div>
        </FiltersSheet>
    );
}
