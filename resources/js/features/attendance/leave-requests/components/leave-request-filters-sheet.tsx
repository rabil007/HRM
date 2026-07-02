import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { Label } from '@/components/ui/label';
import type {
    LeaveRequestEmployeeOption,
    LeaveRequestFilters,
    LeaveRequestTypeOption,
} from '../types';

export function LeaveRequestFiltersSheet({
    open,
    onOpenChange,
    employees,
    leaveTypes,
    showEmployeeFilter = true,
    value,
    onChange,
    onReset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employees: LeaveRequestEmployeeOption[];
    leaveTypes: LeaveRequestTypeOption[];
    showEmployeeFilter?: boolean;
    value: LeaveRequestFilters;
    onChange: (next: LeaveRequestFilters) => void;
    onReset: () => void;
}) {
    return (
        <FiltersSheet open={open} onOpenChange={onOpenChange} onReset={onReset}>
            {showEmployeeFilter ? (
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        Employee
                    </Label>
                    <AppSelect
                        value={value.employee_id}
                        onValueChange={(v) =>
                            onChange({ ...value, employee_id: v })
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
                    Leave type
                </Label>
                <AppSelect
                    value={value.leave_type_id}
                    onValueChange={(v) =>
                        onChange({ ...value, leave_type_id: v })
                    }
                    variant="dark"
                    placeholder="All types"
                >
                    <AppSelectItem value="">All types</AppSelectItem>
                    {leaveTypes.map((leaveType) => (
                        <AppSelectItem
                            key={leaveType.id}
                            value={String(leaveType.id)}
                        >
                            {leaveType.code} — {leaveType.name}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>
        </FiltersSheet>
    );
}
