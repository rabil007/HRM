import { X } from 'lucide-react';
import { useMemo } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDisplayDate } from '@/lib/format-date';
import type { LeaveReportFilters, LeaveReportProps } from './types';

type FilterOptions = LeaveReportProps['filter_options'];

type ActiveFilterChip = {
    key: string;
    label: string;
    onClear: () => void;
};

function formatDateRange(from: string, to: string): string {
    if (from && to) {
        return `${formatDisplayDate(from)} → ${formatDisplayDate(to)}`;
    }

    if (from) {
        return `From ${formatDisplayDate(from)}`;
    }

    return `Until ${formatDisplayDate(to)}`;
}

function resolveLabel(
    key: keyof LeaveReportFilters,
    value: string,
    options: FilterOptions,
): string {
    if (key === 'status') {
        return (
            options.statuses.find((option) => option.value === value)?.label ??
            value
        );
    }

    if (key === 'employee_id') {
        const employee = options.employees.find(
            (option) => String(option.id) === value,
        );

        if (!employee) {
            return value;
        }

        return employee.employee_no
            ? `${employee.name} (${employee.employee_no})`
            : employee.name;
    }

    if (key === 'leave_type_id') {
        return (
            options.leave_types.find((option) => String(option.id) === value)
                ?.name ?? value
        );
    }

    if (key === 'department_id') {
        return (
            options.departments.find((option) => String(option.id) === value)
                ?.name ?? value
        );
    }

    return value;
}

function buildActiveFilterChips({
    filters,
    searchInput,
    options,
    onClearSearch,
    onApply,
}: {
    filters: LeaveReportFilters;
    searchInput: string;
    options: FilterOptions;
    onClearSearch: () => void;
    onApply: (next: Partial<LeaveReportFilters>) => void;
}): ActiveFilterChip[] {
    const chips: ActiveFilterChip[] = [];

    if (searchInput.trim() !== '') {
        chips.push({
            key: 'search',
            label: `Search: ${searchInput.trim()}`,
            onClear: onClearSearch,
        });
    }

    if (filters.leave_from !== '' || filters.leave_to !== '') {
        chips.push({
            key: 'leave_period',
            label: `Leave period: ${formatDateRange(filters.leave_from, filters.leave_to)}`,
            onClear: () =>
                onApply({
                    leave_from: '',
                    leave_to: '',
                }),
        });
    }

    if (filters.department_id !== '') {
        chips.push({
            key: 'department_id',
            label: `Department: ${resolveLabel('department_id', filters.department_id, options)}`,
            onClear: () => onApply({ department_id: '' }),
        });
    }

    if (filters.leave_type_id !== '') {
        chips.push({
            key: 'leave_type_id',
            label: `Leave type: ${resolveLabel('leave_type_id', filters.leave_type_id, options)}`,
            onClear: () => onApply({ leave_type_id: '' }),
        });
    }

    if (filters.employee_id !== '') {
        chips.push({
            key: 'employee_id',
            label: `Employee: ${resolveLabel('employee_id', filters.employee_id, options)}`,
            onClear: () => onApply({ employee_id: '' }),
        });
    }

    if (filters.status !== '') {
        chips.push({
            key: 'status',
            label: `Status: ${resolveLabel('status', filters.status, options)}`,
            onClear: () => onApply({ status: '' }),
        });
    }

    if (filters.submitted_from !== '' || filters.submitted_to !== '') {
        chips.push({
            key: 'submitted',
            label: `Submitted: ${formatDateRange(filters.submitted_from, filters.submitted_to)}`,
            onClear: () =>
                onApply({
                    submitted_from: '',
                    submitted_to: '',
                }),
        });
    }

    if (filters.decided_from !== '' || filters.decided_to !== '') {
        chips.push({
            key: 'decided',
            label: `Decided: ${formatDateRange(filters.decided_from, filters.decided_to)}`,
            onClear: () =>
                onApply({
                    decided_from: '',
                    decided_to: '',
                }),
        });
    }

    return chips;
}

export function countSheetFilters(filters: LeaveReportFilters): number {
    let count = 0;

    if (filters.employee_id !== '') {
        count += 1;
    }

    if (filters.status !== '') {
        count += 1;
    }

    if (filters.submitted_from !== '' || filters.submitted_to !== '') {
        count += 1;
    }

    if (filters.decided_from !== '' || filters.decided_to !== '') {
        count += 1;
    }

    return count;
}

export function LeaveReportActiveFilters({
    filters,
    searchInput,
    options,
    onClearSearch,
    onApply,
}: {
    filters: LeaveReportFilters;
    searchInput: string;
    options: FilterOptions;
    onClearSearch: () => void;
    onApply: (next: Partial<LeaveReportFilters>) => void;
}) {
    const chips = useMemo(
        () =>
            buildActiveFilterChips({
                filters,
                searchInput,
                options,
                onClearSearch,
                onApply,
            }),
        [filters, onApply, onClearSearch, options, searchInput],
    );

    if (chips.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-medium text-muted-foreground/80">
                Active filters
            </span>

            {chips.map((chip) => (
                <Badge
                    key={chip.key}
                    variant="outline"
                    className="max-w-[calc(100vw-4rem)] gap-1 border-primary/25 bg-primary/5 pr-1 pl-2.5 font-normal sm:max-w-md dark:border-white/10"
                >
                    <span className="truncate">{chip.label}</span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-5 w-5 shrink-0 rounded-full hover:bg-primary/10"
                        onClick={chip.onClear}
                        aria-label={`Remove ${chip.label} filter`}
                    >
                        <X className="h-3 w-3" />
                    </Button>
                </Badge>
            ))}
        </div>
    );
}
