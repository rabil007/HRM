import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    destroy as destroySalaryInput,
    store as storeSalaryInput,
    update as updateSalaryInput,
} from '@/actions/App/Http/Controllers/Payroll/SalaryInputController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type {
    OfficePayrollRecordListItem,
    SalaryInput,
    SalaryInputFormData,
    SalaryInputTypeOption,
} from '../types';
import { formatTimesheetAmount } from '../types';

const reloadProps = ['salary_inputs_by_employee'] as const;

function emptyForm(employeeId: number, defaultTypeId: number): SalaryInputFormData {
    return {
        employee_id: employeeId,
        salary_input_type_id: defaultTypeId,
        amount: '',
        notes: '',
    };
}

function inputToForm(input: SalaryInput): SalaryInputFormData {
    return {
        employee_id: input.employee_id,
        salary_input_type_id: input.salary_input_type_id,
        amount: input.amount,
        notes: input.notes ?? '',
    };
}

export function OfficeSalaryInputsSheet({
    open,
    onOpenChange,
    periodId,
    record,
    inputs,
    typeOptions,
    canCreate,
    canUpdate,
    canDelete,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    periodId: number;
    record: OfficePayrollRecordListItem | null;
    inputs: SalaryInput[];
    typeOptions: SalaryInputTypeOption[];
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
}) {
    const defaultTypeId = typeOptions[0]?.value ?? 0;
    const [editingInput, setEditingInput] = useState<SalaryInput | null>(null);
    const [isFormVisible, setIsFormVisible] = useState(false);
    const [prevRecordId, setPrevRecordId] = useState<number | null>(null);
    const [prevOpen, setPrevOpen] = useState(false);

    const form = useForm<SalaryInputFormData>(emptyForm(record?.employee.id ?? 0, defaultTypeId));

    const currentRecordId = record?.employee.id ?? null;

    if (open !== prevOpen || currentRecordId !== prevRecordId) {
        setPrevOpen(open);
        setPrevRecordId(currentRecordId);

        if (open && record) {
            setEditingInput(null);
            setIsFormVisible(false);
            form.clearErrors();
            form.setData(emptyForm(record.employee.id, defaultTypeId));
        }
    }

    const canSave = editingInput ? canUpdate : canCreate;
    const showForm = isFormVisible || editingInput !== null;

    const handleCloseForm = () => {
        setEditingInput(null);
        setIsFormVisible(false);
        form.clearErrors();

        if (record) {
            form.setData(emptyForm(record.employee.id, defaultTypeId));
        }
    };

    const handleSubmit = () => {
        if (!record) {
            return;
        }

        const options = {
            preserveScroll: true,
            only: [...reloadProps],
            onSuccess: () => handleCloseForm(),
        };

        if (editingInput) {
            form.put(updateSalaryInput.url({ payrollPeriod: periodId, salaryInput: editingInput.id }), options);

            return;
        }

        form.post(storeSalaryInput.url(periodId), options);
    };

    const handleDelete = (input: SalaryInput) => {
        router.delete(
            destroySalaryInput.url({ payrollPeriod: periodId, salaryInput: input.id }),
            {
                preserveScroll: true,
                only: [...reloadProps],
                onSuccess: () => {
                    if (editingInput?.id === input.id) {
                        handleCloseForm();
                    }
                },
            },
        );
    };

    const handleEdit = (input: SalaryInput) => {
        setEditingInput(input);
        setIsFormVisible(true);
        form.clearErrors();
        form.setData(inputToForm(input));
    };

    const handleAdd = () => {
        if (!record) {
            return;
        }

        setEditingInput(null);
        setIsFormVisible(true);
        form.clearErrors();
        form.setData(emptyForm(record.employee.id, defaultTypeId));
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none p-0 glass-card sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {record?.employee.name ?? 'Salary inputs'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {record?.employee.employee_no
                            ? `Employee ${record.employee.employee_no} — additions and deductions for this pay period`
                            : 'Additions and deductions for this pay period'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto p-8">
                    <div className="space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold tracking-tight">Salary input lines</h3>
                            {canCreate ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="rounded-lg"
                                    onClick={handleAdd}
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add line
                                </Button>
                            ) : null}
                        </div>

                        {inputs.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-border/60 px-4 py-6 text-sm text-muted-foreground">
                                No salary inputs yet. Add bonus, commission, or deduction lines, then click{' '}
                                <strong>Update payroll</strong> to refresh gross and net pay.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {inputs.map((input) => (
                                    <div
                                        key={input.id}
                                        className="flex items-start justify-between gap-3 rounded-xl border border-border/60 bg-card/60 px-4 py-3"
                                    >
                                        <div className="min-w-0 space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-medium">{input.type_label}</span>
                                                <Badge
                                                    variant="outline"
                                                    className={cn(
                                                        input.is_addition
                                                            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200'
                                                            : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200',
                                                    )}
                                                >
                                                    {input.is_addition ? 'Addition' : 'Deduction'}
                                                </Badge>
                                            </div>
                                            <div className="text-sm font-semibold">
                                                {formatTimesheetAmount(input.amount)}
                                            </div>
                                            {input.notes ? (
                                                <p className="text-xs text-muted-foreground">{input.notes}</p>
                                            ) : null}
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1">
                                            {canUpdate ? (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 rounded-lg"
                                                    onClick={() => handleEdit(input)}
                                                >
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            ) : null}
                                            {canDelete ? (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 rounded-lg text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                    onClick={() => handleDelete(input)}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            ) : null}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {showForm && canSave ? (
                        <form
                            className="space-y-4 rounded-xl border border-border/60 bg-muted/20 p-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                handleSubmit();
                            }}
                        >
                            <h3 className="text-sm font-semibold tracking-tight">
                                {editingInput ? 'Edit salary input' : 'Add salary input'}
                            </h3>

                            <div className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Type
                                </Label>
                                <Select
                                    value={String(form.data.salary_input_type_id)}
                                    onValueChange={(value) =>
                                        form.setData('salary_input_type_id', Number(value))
                                    }
                                >
                                    <SelectTrigger className="h-11 rounded-xl border-border bg-card">
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {typeOptions.map((option) => (
                                            <SelectItem key={option.value} value={String(option.value)}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.salary_input_type_id} className="text-xs" />
                            </div>

                            <div className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Amount
                                </Label>
                                <Input
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.amount}
                                    onChange={(event) => form.setData('amount', event.target.value)}
                                />
                                <InputError message={form.errors.amount} className="text-xs" />
                            </div>

                            <div className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Notes
                                </Label>
                                <Textarea
                                    className="min-h-20 rounded-xl border-border bg-card"
                                    value={form.data.notes}
                                    onChange={(event) => form.setData('notes', event.target.value)}
                                />
                                <InputError message={form.errors.notes} className="text-xs" />
                            </div>

                            <div className="flex items-center justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="rounded-lg"
                                    onClick={handleCloseForm}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" className="rounded-lg" disabled={form.processing}>
                                    {editingInput ? 'Save changes' : 'Add line'}
                                </Button>
                            </div>
                        </form>
                    ) : null}
                </div>
            </SheetContent>
        </Sheet>
    );
}
