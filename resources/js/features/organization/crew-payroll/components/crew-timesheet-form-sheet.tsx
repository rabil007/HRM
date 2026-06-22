import type { InertiaFormProps } from '@inertiajs/react';
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
import type { CrewPayrollRow, CrewTimesheetFormData } from '../types';

export function CrewTimesheetFormSheet({
    open,
    onOpenChange,
    row,
    canSave,
    form,
    onSubmit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    row: CrewPayrollRow | null;
    canSave: boolean;
    form: InertiaFormProps<CrewTimesheetFormData>;
    onSubmit: () => void;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none p-0 glass-card sm:max-w-lg"
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
                    <div className="space-y-5">
                        <h3 className="text-sm font-semibold tracking-tight">Worked days</h3>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Standby from
                                </Label>
                                <Input
                                    type="date"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.standby_from}
                                    onChange={(e) => form.setData('standby_from', e.target.value)}
                                    disabled={!canSave}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Standby to
                                </Label>
                                <Input
                                    type="date"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.standby_to}
                                    onChange={(e) => form.setData('standby_to', e.target.value)}
                                    disabled={!canSave}
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Standby days
                            </Label>
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                className="h-11 rounded-xl border-border bg-card"
                                value={form.data.standby_days}
                                onChange={(e) => form.setData('standby_days', e.target.value)}
                                disabled={!canSave}
                            />
                            {form.errors.standby_days ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.standby_days}</div>
                            ) : null}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Onsite from
                                </Label>
                                <Input
                                    type="date"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.onsite_from}
                                    onChange={(e) => form.setData('onsite_from', e.target.value)}
                                    disabled={!canSave}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Onsite to
                                </Label>
                                <Input
                                    type="date"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.onsite_to}
                                    onChange={(e) => form.setData('onsite_to', e.target.value)}
                                    disabled={!canSave}
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Onsite days
                            </Label>
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                className="h-11 rounded-xl border-border bg-card"
                                value={form.data.onsite_days}
                                onChange={(e) => form.setData('onsite_days', e.target.value)}
                                disabled={!canSave}
                            />
                            {form.errors.onsite_days ? (
                                <div className="text-xs font-medium text-destructive">{form.errors.onsite_days}</div>
                            ) : null}
                        </div>
                    </div>

                    <div className="space-y-5 border-t border-border/60 pt-4">
                        <h3 className="text-sm font-semibold tracking-tight">Salary inputs</h3>

                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Overtime
                                </Label>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.overtime_amount}
                                    onChange={(e) => form.setData('overtime_amount', e.target.value)}
                                    disabled={!canSave}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Additions
                                </Label>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.additional_amount}
                                    onChange={(e) => form.setData('additional_amount', e.target.value)}
                                    disabled={!canSave}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                    Deductions
                                </Label>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="h-11 rounded-xl border-border bg-card"
                                    value={form.data.deduction_amount}
                                    onChange={(e) => form.setData('deduction_amount', e.target.value)}
                                    disabled={!canSave}
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Remarks
                            </Label>
                            <Textarea
                                className="min-h-24 rounded-xl border-border bg-card"
                                value={form.data.remarks}
                                onChange={(e) => form.setData('remarks', e.target.value)}
                                disabled={!canSave}
                            />
                        </div>
                    </div>
                </div>

                <div className="border-t border-border/60 p-8">
                    <Button className="w-full rounded-xl" disabled={form.processing || !canSave} onClick={onSubmit}>
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
