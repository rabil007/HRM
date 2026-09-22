import { X } from 'lucide-react';
import { useMemo } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type {
    LeaveBalanceReportFilters,
    LeaveBalanceReportProps,
} from './types';

type FilterOptions = LeaveBalanceReportProps['filter_options'];

type ActiveFilterChip = {
    key: string;
    label: string;
    onClear: () => void;
};

function resolveLabel(
    key: keyof LeaveBalanceReportFilters,
    value: string,
    options: FilterOptions,
): string {
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

    if (key === 'department_id') {
        return (
            options.departments.find((option) => String(option.id) === value)
                ?.name ?? value
        );
    }

    if (key === 'leave_type_id') {
        return (
            options.leave_types.find((option) => String(option.id) === value)
                ?.name ?? value
        );
    }

    if (key === 'category') {
        return (
            options.categories.find((option) => option.value === value)
                ?.label ?? value
        );
    }

    if (key === 'employee_status') {
        return (
            options.employee_statuses.find((option) => option.value === value)
                ?.label ?? value
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
    filters: LeaveBalanceReportFilters;
    searchInput: string;
    options: FilterOptions;
    onClearSearch: () => void;
    onApply: (next: Partial<LeaveBalanceReportFilters>) => void;
}): ActiveFilterChip[] {
    const chips: ActiveFilterChip[] = [];

    if (searchInput.trim() !== '') {
        chips.push({
            key: 'search',
            label: `Search: ${searchInput.trim()}`,
            onClear: onClearSearch,
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

    if (filters.category !== '') {
        chips.push({
            key: 'category',
            label: `Category: ${resolveLabel('category', filters.category, options)}`,
            onClear: () => onApply({ category: '' }),
        });
    }

    if (filters.employee_status !== '') {
        chips.push({
            key: 'employee_status',
            label: `Status: ${resolveLabel('employee_status', filters.employee_status, options)}`,
            onClear: () => onApply({ employee_status: '' }),
        });
    }

    return chips;
}

/** Secondary filters (not on the primary toolbar). */
export function countSheetFilters(filters: LeaveBalanceReportFilters): number {
    let count = 0;

    if (filters.employee_id !== '') {
        count += 1;
    }

    if (filters.category !== '') {
        count += 1;
    }

    if (filters.employee_status !== '') {
        count += 1;
    }

    return count;
}

export function LeaveBalanceReportActiveFilters({
    filters,
    searchInput,
    options,
    onClearSearch,
    onApply,
}: {
    filters: LeaveBalanceReportFilters;
    searchInput: string;
    options: FilterOptions;
    onClearSearch: () => void;
    onApply: (next: Partial<LeaveBalanceReportFilters>) => void;
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
