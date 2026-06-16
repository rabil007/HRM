import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AttendanceRecordFilters } from '../types';

const filterInputClass =
    'h-11 w-full rounded-xl border-input bg-background/80 dark:border-white/5 dark:bg-white/5 focus-visible:ring-primary/40';

export function RecordFiltersSheet({
    open,
    onOpenChange,
    employees,
    statusOptions,
    sourceOptions,
    showEmployeeFilter = true,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employees: Array<{ id: number; employee_no: string | null; name: string }>;
    statusOptions: Array<{ value: string; label: string }>;
    sourceOptions: Array<{ value: string; label: string }>;
    showEmployeeFilter?: boolean;
    value: AttendanceRecordFilters;
    onChange: (next: AttendanceRecordFilters) => void;
    onReset: () => void;
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        From
                    </Label>
                    <Input
                        type="date"
                        value={value.date_from}
                        onChange={(e) => onChange({ ...value, date_from: e.target.value })}
                        className={filterInputClass}
                    />
                </div>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        To
                    </Label>
                    <Input
                        type="date"
                        value={value.date_to}
                        onChange={(e) => onChange({ ...value, date_to: e.target.value })}
                        className={filterInputClass}
                    />
                </div>
            </div>

            {showEmployeeFilter ? (
                <div className="space-y-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Employee
                    </Label>
                    <AppSelect
                        value={value.employee_id}
                        onValueChange={(v) => onChange({ ...value, employee_id: v })}
                        variant="dark"
                        placeholder="All employees"
                    >
                        <AppSelectItem value="">All employees</AppSelectItem>
                        {employees.map((employee) => (
                            <AppSelectItem key={employee.id} value={String(employee.id)}>
                                {employee.employee_no ? `${employee.employee_no} — ${employee.name}` : employee.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>
            ) : null}

            <div className="space-y-2">
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Status
                </Label>
                <AppSelect
                    value={value.status}
                    onValueChange={(v) => onChange({ ...value, status: v })}
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
                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                    Source
                </Label>
                <AppSelect
                    value={value.source}
                    onValueChange={(v) => onChange({ ...value, source: v })}
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
