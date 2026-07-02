import { router } from '@inertiajs/react';
import { UserRound } from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Label } from '@/components/ui/label';
import type { CalendarEmployeeOption } from '../types';

export function CalendarEmployeeSelect({
    year,
    employees,
    selectedEmployeeId,
}: {
    year: number;
    employees: CalendarEmployeeOption[];
    selectedEmployeeId: number | null;
}) {
    const navigate = (employeeId: string) => {
        const params: { year: number; employee_id?: number } = { year };

        if (employeeId !== '') {
            params.employee_id = Number(employeeId);
        }

        router.get('/attendance/calendar', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    return (
        <div className="space-y-2 rounded-2xl border glass-card border-border/60 bg-card/80 p-3 dark:border-white/6 dark:bg-white/4">
            <Label className="flex items-center gap-2 text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                <UserRound className="size-3.5" />
                Employee
            </Label>
            <AppSelect
                value={
                    selectedEmployeeId !== null
                        ? String(selectedEmployeeId)
                        : ''
                }
                onValueChange={navigate}
                variant="card"
                placeholder="Select employee"
            >
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
    );
}
