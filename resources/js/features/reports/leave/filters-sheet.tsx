import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    EmployeeOption,
    LeaveReportFilters,
    LeaveReportProps,
    ReportOption,
    SelectOption,
} from './types';

type FilterOptions = LeaveReportProps['filter_options'];

function SelectFilter({
    label,
    value,
    options,
    onChange,
    formatLabel,
}: {
    label: string;
    value: string;
    options: Array<ReportOption | SelectOption | EmployeeOption>;
    onChange: (value: string) => void;
    formatLabel?: (option: EmployeeOption) => string;
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
                        'label' in option
                            ? option.label
                            : formatLabel
                              ? formatLabel(option as EmployeeOption)
                              : option.name;

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
    hint,
    from,
    to,
    onFromChange,
    onToChange,
}: {
    label: string;
    hint?: string;
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
            {hint ? (
                <p className="text-xs text-muted-foreground">{hint}</p>
            ) : null}
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

export function LeaveReportFiltersSheet({
    open,
    onOpenChange,
    filters,
    options,
    onApply,
    onClear,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: LeaveReportFilters;
    options: FilterOptions;
    onApply: (filters: LeaveReportFilters) => void;
    onClear: () => void;
}) {
    const [draft, setDraft] = useState(filters);

    const close = (nextOpen: boolean): void => {
        if (!nextOpen && open) {
            onApply(draft);
        }

        onOpenChange(nextOpen);
    };

    const set = (key: keyof LeaveReportFilters, value: string): void =>
        setDraft((current) => ({ ...current, [key]: value }));

    return (
        <FiltersSheet
            open={open}
            onOpenChange={close}
            title="Leave report filters"
            resetText="Clear Filters"
            onReset={() => {
                onOpenChange(false);
                onClear();
            }}
        >
            <div className="space-y-6">
                <section className="space-y-4">
                    <h3 className="text-sm font-semibold">
                        Employee & organization
                    </h3>
                    <SelectFilter
                        label="Employee"
                        value={draft.employee_id}
                        options={options.employees}
                        onChange={(value) => set('employee_id', value)}
                        formatLabel={(employee) =>
                            employee.employee_no
                                ? `${employee.name} (${employee.employee_no})`
                                : employee.name
                        }
                    />
                    <SelectFilter
                        label="Department"
                        value={draft.department_id}
                        options={options.departments}
                        onChange={(value) => set('department_id', value)}
                    />
                    <SelectFilter
                        label="Branch"
                        value={draft.branch_id}
                        options={options.branches}
                        onChange={(value) => set('branch_id', value)}
                    />
                </section>

                <section className="space-y-4">
                    <h3 className="text-sm font-semibold">Leave details</h3>
                    <SelectFilter
                        label="Leave type"
                        value={draft.leave_type_id}
                        options={options.leave_types}
                        onChange={(value) => set('leave_type_id', value)}
                    />
                    <SelectFilter
                        label="Status"
                        value={draft.status}
                        options={options.statuses}
                        onChange={(value) => set('status', value)}
                    />
                </section>

                <section className="space-y-4">
                    <h3 className="text-sm font-semibold">
                        Request / decision dates
                    </h3>
                    <DateRange
                        label="Submitted"
                        from={draft.submitted_from}
                        to={draft.submitted_to}
                        onFromChange={(value) => set('submitted_from', value)}
                        onToChange={(value) => set('submitted_to', value)}
                    />
                    <DateRange
                        label="Decided"
                        from={draft.decided_from}
                        to={draft.decided_to}
                        onFromChange={(value) => set('decided_from', value)}
                        onToChange={(value) => set('decided_to', value)}
                    />
                </section>
            </div>
        </FiltersSheet>
    );
}
