import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AttendanceRecordFilters } from '../types';

export function AttendanceRecordsFiltersSheet({
    open,
    onOpenChange,
    value,
    employees,
    statusOptions,
    sourceOptions,
    showEmployeeFilter,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    value: AttendanceRecordFilters;
    employees: Array<{ id: number; employee_no: string | null; name: string }>;
    statusOptions: Array<{ value: string; label: string }>;
    sourceOptions: Array<{ value: string; label: string }>;
    showEmployeeFilter: boolean;
    onChange: (next: Partial<AttendanceRecordFilters>) => void;
    onReset: () => void;
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="space-y-2">
                <Label
                    htmlFor="attendance-records-mobile-date-from"
                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                >
                    From
                </Label>
                <Input
                    id="attendance-records-mobile-date-from"
                    type="date"
                    value={value.date_from}
                    onChange={(e) => onChange({ date_from: e.target.value })}
                />
            </div>

            <div className="space-y-2">
                <Label
                    htmlFor="attendance-records-mobile-date-to"
                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                >
                    To
                </Label>
                <Input
                    id="attendance-records-mobile-date-to"
                    type="date"
                    value={value.date_to}
                    onChange={(e) => onChange({ date_to: e.target.value })}
                />
            </div>

            {showEmployeeFilter ? (
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Employee
                    </Label>
                    <AppSelect
                        value={value.employee_id || ''}
                        onValueChange={(employee_id) =>
                            onChange({ employee_id })
                        }
                        variant="dark"
                        placeholder="All employees"
                    >
                        <AppSelectItem value="">All employees</AppSelectItem>
                        {employees.map((employee) => (
                            <AppSelectItem
                                key={employee.id}
                                value={String(employee.id)}
                            >
                                {employee.employee_no
                                    ? `${employee.employee_no} — ${employee.name}`
                                    : employee.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>
            ) : null}

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Status
                </Label>
                <AppSelect
                    value={value.status || ''}
                    onValueChange={(status) => onChange({ status })}
                    variant="dark"
                    placeholder="All statuses"
                >
                    <AppSelectItem value="">All statuses</AppSelectItem>
                    {statusOptions.map((option) => (
                        <AppSelectItem key={option.value} value={option.value}>
                            {option.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            <div className="space-y-2">
                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                    Source
                </Label>
                <AppSelect
                    value={value.source || ''}
                    onValueChange={(source) => onChange({ source })}
                    variant="dark"
                    placeholder="All sources"
                >
                    <AppSelectItem value="">All sources</AppSelectItem>
                    {sourceOptions.map((option) => (
                        <AppSelectItem key={option.value} value={option.value}>
                            {option.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>
        </FiltersSheet>
    );
}
