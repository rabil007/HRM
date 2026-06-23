import type { InertiaFormProps } from '@inertiajs/react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import type {
    SalaryAdjustment,
    SalaryAdjustmentEmployeeOption,
    SalaryAdjustmentFormData,
    SalaryAdjustmentPeriodOption,
    SalaryAdjustmentType,
} from '../types';

const inputClass = 'h-11 rounded-xl border-border bg-card transition-all focus-visible:ring-primary/40';

export function SalaryAdjustmentFormSheet({
    open,
    onOpenChange,
    adjustment,
    employees,
    periods,
    typeOptions,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    adjustment: SalaryAdjustment | null;
    employees: SalaryAdjustmentEmployeeOption[];
    periods: SalaryAdjustmentPeriodOption[];
    typeOptions: Array<{ value: string; label: string }>;
    form: InertiaFormProps<SalaryAdjustmentFormData>;
    onSubmit: () => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent side="right" className="glass-card flex w-full flex-col rounded-none p-0 sm:max-w-md">
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {adjustment ? 'Edit salary adjustment' : 'New salary adjustment'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        Record a bonus, deduction, or other payroll adjustment for an employee.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto p-8">
                    <div className="space-y-2">
                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                            Employee
                        </Label>
                        <AppSelect
                            value={String(form.data.employee_id ?? '')}
                            onValueChange={(value) => form.setData('employee_id', value ? Number(value) : '')}
                            variant="card"
                            placeholder="Select employee"
                        >
                            {employees.map((employee) => (
                                <AppSelectItem key={employee.id} value={String(employee.id)}>
                                    {employee.employee_no
                                        ? `${employee.employee_no} — ${employee.name}`
                                        : employee.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.errors.employee_id ? (
                            <div className="text-xs font-medium text-destructive">{form.errors.employee_id}</div>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                            Pay period (optional)
                        </Label>
                        <AppSelect
                            value={form.data.period_id ? String(form.data.period_id) : 'none'}
                            onValueChange={(value) =>
                                form.setData('period_id', value === 'none' ? '' : Number(value))
                            }
                            variant="card"
                            placeholder="No specific period"
                        >
                            <AppSelectItem value="none">No specific period</AppSelectItem>
                            {periods.map((period) => (
                                <AppSelectItem key={period.id} value={String(period.id)}>
                                    {period.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="space-y-2">
                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                            Type
                        </Label>
                        <AppSelect
                            value={form.data.type || ''}
                            onValueChange={(value) => form.setData('type', value as SalaryAdjustmentType)}
                            variant="card"
                            placeholder="Select type"
                        >
                            {typeOptions.map((option) => (
                                <AppSelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                        {form.errors.type ? (
                            <div className="text-xs font-medium text-destructive">{form.errors.type}</div>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                            Amount
                        </Label>
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            value={form.data.amount}
                            onChange={(event) => form.setData('amount', event.target.value)}
                            className={inputClass}
                        />
                        {form.errors.amount ? (
                            <div className="text-xs font-medium text-destructive">{form.errors.amount}</div>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                            Reason
                        </Label>
                        <Textarea
                            value={form.data.reason}
                            onChange={(event) => form.setData('reason', event.target.value)}
                            className="min-h-24 rounded-xl border-border bg-card"
                        />
                        {form.errors.reason ? (
                            <div className="text-xs font-medium text-destructive">{form.errors.reason}</div>
                        ) : null}
                    </div>
                </div>

                <div className="border-t border-border/60 p-6">
                    <Button className="h-11 w-full rounded-xl" onClick={onSubmit} disabled={form.processing}>
                        {form.processing ? 'Saving…' : adjustment ? 'Update adjustment' : 'Create adjustment'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
