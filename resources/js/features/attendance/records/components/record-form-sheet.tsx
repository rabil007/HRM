import type { InertiaFormProps } from '@inertiajs/react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import type { AttendanceRecord, AttendanceRecordFormData } from '../types';

const inputClass = 'rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all';

export function RecordFormSheet({
    open,
    onOpenChange,
    record,
    form,
    employees,
    statusOptions,
    linkedEmployeeId,
    canManage,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    record: AttendanceRecord | null;
    form: InertiaFormProps<AttendanceRecordFormData>;
    employees: Array<{ id: number; employee_no: string | null; name: string }>;
    statusOptions: Array<{ value: string; label: string }>;
    linkedEmployeeId: number | null;
    canManage: boolean;
    onSubmit: () => void;
}) {
    const employeeLocked = !canManage && linkedEmployeeId !== null;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="w-full sm:max-w-md p-0 flex flex-col glass-card rounded-none">
                <SheetHeader className="p-8 pb-6 border-b border-border/60">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {record ? 'Edit record' : 'New record'}
                    </SheetTitle>
                    <SheetDescription className="text-sm text-muted-foreground/80 mt-1">
                        {record ? 'Update attendance details.' : 'Add a manual attendance entry.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 overflow-y-auto p-8 space-y-5">
                    <div className="space-y-2">
                        <Label htmlFor="employee_id">Employee</Label>
                        <AppSelect
                            value={form.data.employee_id}
                            onValueChange={(value) => form.setData('employee_id', value)}
                            variant="card"
                            disabled={employeeLocked}
                        >
                            {employees.map((employee) => (
                                <AppSelectItem key={employee.id} value={String(employee.id)}>
                                    {employee.name}
                                    {employee.employee_no ? ` (${employee.employee_no})` : ''}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.errors.employee_id ? (
                            <div className="text-xs font-medium text-destructive">{form.errors.employee_id}</div>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="date">Date</Label>
                        <Input
                            id="date"
                            type="date"
                            className={inputClass}
                            value={form.data.date}
                            onChange={(e) => form.setData('date', e.target.value)}
                        />
                        {form.errors.date ? <div className="text-xs font-medium text-destructive">{form.errors.date}</div> : null}
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="clock_in">Clock in</Label>
                            <Input
                                id="clock_in"
                                type="datetime-local"
                                className={inputClass}
                                value={form.data.clock_in}
                                onChange={(e) => form.setData('clock_in', e.target.value)}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="clock_out">Clock out</Label>
                            <Input
                                id="clock_out"
                                type="datetime-local"
                                className={inputClass}
                                value={form.data.clock_out}
                                onChange={(e) => form.setData('clock_out', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="status">Status</Label>
                            <AppSelect
                                value={form.data.status}
                                onValueChange={(value) => form.setData('status', value)}
                                variant="card"
                            >
                                {statusOptions.map((option) => (
                                    <AppSelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="late_minutes">Late minutes</Label>
                            <Input
                                id="late_minutes"
                                inputMode="numeric"
                                className={inputClass}
                                value={form.data.late_minutes}
                                onChange={(e) => form.setData('late_minutes', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="notes">Notes</Label>
                        <Textarea
                            id="notes"
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            rows={3}
                        />
                    </div>
                </div>

                <div className="border-t border-border/60 p-8">
                    <Button className="w-full" onClick={onSubmit} disabled={form.processing}>
                        {record ? 'Save changes' : 'Create record'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
