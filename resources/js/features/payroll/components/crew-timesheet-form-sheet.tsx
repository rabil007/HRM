import type { InertiaFormProps } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { calculateInclusiveDays } from '../lib/calculate-inclusive-days';
import type { CrewPayrollRow, CrewTimesheetFormData } from '../types';

type OperationalRangeField = 'sign_on_standby' | 'onsite' | 'sign_off_standby';

function OperationalRange({
    form,
    field,
    label,
    canSave,
    errors,
}: {
    form: InertiaFormProps<CrewTimesheetFormData>;
    field: OperationalRangeField;
    label: string;
    canSave: boolean;
    errors: Record<string, string | undefined>;
}) {
    const fromKey = `${field}_from` as keyof CrewTimesheetFormData;
    const toKey = `${field}_to` as keyof CrewTimesheetFormData;
    const days = calculateInclusiveDays(
        String(form.data[fromKey] ?? ''),
        String(form.data[toKey] ?? ''),
    );

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        {label} from
                    </Label>
                    <Input
                        type="date"
                        className="h-11 rounded-xl border-border bg-card"
                        value={String(form.data[fromKey] ?? '')}
                        onChange={(e) => form.setData(fromKey, e.target.value)}
                        disabled={!canSave}
                    />
                    <InputError message={errors[fromKey]} className="text-xs" />
                </div>
                <div className="space-y-2">
                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                        {label} to
                    </Label>
                    <Input
                        type="date"
                        className="h-11 rounded-xl border-border bg-card"
                        value={String(form.data[toKey] ?? '')}
                        onChange={(e) => form.setData(toKey, e.target.value)}
                        disabled={!canSave}
                    />
                    <InputError message={errors[toKey]} className="text-xs" />
                </div>
            </div>
            <p className="text-xs text-muted-foreground">
                {days && Number(days) > 0
                    ? `${days} day(s) — calculated on save.`
                    : 'No dates set.'}
            </p>
        </div>
    );
}

export function CrewTimesheetFormSheet({
    open,
    onOpenChange,
    row,
    canSave,
    form,
    errors,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    row: CrewPayrollRow | null;
    canSave: boolean;
    form: InertiaFormProps<CrewTimesheetFormData>;
    errors: Record<string, string | undefined>;
    onSubmit: () => void;
}) {
    const hasErrors = Object.keys(errors).length > 0;
    const isMonthlyCrew = row?.salary_structure === 'monthly';

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {row?.employee.name ?? 'Crew timesheet'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {row?.employee.employee_no
                            ? `Employee ${row.employee.employee_no} — worked days and salary inputs`
                            : 'Worked days and salary inputs for this pay period'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    {hasErrors ? (
                        <div className="rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                            Please fix the highlighted fields before saving.
                        </div>
                    ) : null}

                    <div className="space-y-5">
                        <h3 className="text-sm font-semibold tracking-tight">
                            {isMonthlyCrew
                                ? 'Unpaid leave'
                                : 'Operational days'}
                        </h3>

                        {isMonthlyCrew ? (
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                    Unpaid leave days
                                </Label>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.unpaid_leave_days}
                                    onChange={(e) =>
                                        form.setData(
                                            'unpaid_leave_days',
                                            e.target.value,
                                        )
                                    }
                                    disabled={!canSave}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Unpaid leave days reduce monthly pay for
                                    this period.
                                </p>
                                <InputError
                                    message={errors.unpaid_leave_days}
                                    className="text-xs"
                                />
                            </div>
                        ) : (
                            <>
                                <OperationalRange
                                    form={form}
                                    field="sign_on_standby"
                                    label="Sign-on standby"
                                    canSave={canSave}
                                    errors={errors}
                                />
                                <OperationalRange
                                    form={form}
                                    field="onsite"
                                    label="Onsite"
                                    canSave={canSave}
                                    errors={errors}
                                />
                                <OperationalRange
                                    form={form}
                                    field="sign_off_standby"
                                    label="Sign-off standby"
                                    canSave={canSave}
                                    errors={errors}
                                />
                            </>
                        )}
                    </div>

                    <div className="space-y-5 border-t border-border/60 pt-4">
                        <h3 className="text-sm font-semibold tracking-tight">
                            Salary inputs
                        </h3>

                        <div className="grid grid-cols-3 gap-4">
                            {!isMonthlyCrew ? (
                                <div className="space-y-2">
                                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                        Overtime (hrs)
                                    </Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        className="h-11 rounded-xl border-border bg-card"
                                        value={form.data.overtime_hours}
                                        onChange={(e) =>
                                            form.setData(
                                                'overtime_hours',
                                                e.target.value,
                                            )
                                        }
                                        disabled={!canSave}
                                    />
                                    <InputError
                                        message={errors.overtime_hours}
                                        className="text-xs"
                                    />
                                </div>
                            ) : null}
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                    Additions
                                </Label>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.additional_amount}
                                    onChange={(e) =>
                                        form.setData(
                                            'additional_amount',
                                            e.target.value,
                                        )
                                    }
                                    disabled={!canSave}
                                />
                                <InputError
                                    message={errors.additional_amount}
                                    className="text-xs"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                    Deductions
                                </Label>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.deduction_amount}
                                    onChange={(e) =>
                                        form.setData(
                                            'deduction_amount',
                                            e.target.value,
                                        )
                                    }
                                    disabled={!canSave}
                                />
                                <InputError
                                    message={errors.deduction_amount}
                                    className="text-xs"
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                Remarks
                            </Label>
                            <Textarea
                                className="min-h-24 rounded-xl border-border bg-card"
                                value={form.data.remarks}
                                onChange={(e) =>
                                    form.setData('remarks', e.target.value)
                                }
                                disabled={!canSave}
                            />
                            <InputError
                                message={errors.remarks}
                                className="text-xs"
                            />
                        </div>
                    </div>
                </div>

                <div className="border-t border-border/60 p-8">
                    <Button
                        className="w-full rounded-xl"
                        disabled={form.processing || !canSave}
                        onClick={onSubmit}
                    >
                        Save timesheet
                    </Button>
                    {!canSave ? (
                        <p className="mt-3 text-center text-xs text-muted-foreground">
                            This pay period is not editable.
                        </p>
                    ) : null}
                </div>
            </SheetContent>
        </Sheet>
    );
}
