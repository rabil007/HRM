import type { InertiaFormProps } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { defaultLeaveApprovalPolicyStepFormData } from '../types';
import type {
    LeaveApprovalApproverTypeOption,
    LeaveApprovalPolicy,
    LeaveApprovalPolicyEmployeeOption,
    LeaveApprovalPolicyFormData,
} from '../types';

const inputClass =
    'rounded-xl border-border bg-card focus-visible:ring-primary/40 h-11 transition-all';

function approverTypeMeta(
    approverTypes: LeaveApprovalApproverTypeOption[],
    value: string,
): LeaveApprovalApproverTypeOption | undefined {
    return approverTypes.find((type) => type.value === value);
}

export function LeaveApprovalPolicyFormSheet({
    open,
    onOpenChange,
    policy,
    form,
    approverTypes,
    employees,
    onSubmit,
    onMoveStep,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    policy: LeaveApprovalPolicy | null;
    form: InertiaFormProps<LeaveApprovalPolicyFormData>;
    approverTypes: LeaveApprovalApproverTypeOption[];
    employees: LeaveApprovalPolicyEmployeeOption[];
    onSubmit: () => void;
    onMoveStep: (index: number, direction: 'up' | 'down') => void;
}) {
    const updateStep = (
        index: number,
        patch: Partial<LeaveApprovalPolicyFormData['steps'][number]>,
    ) => {
        form.setData(
            'steps',
            form.data.steps.map((step, i) =>
                i === index ? { ...step, ...patch } : step,
            ),
        );
    };

    const addStep = () => {
        form.setData('steps', [
            ...form.data.steps,
            defaultLeaveApprovalPolicyStepFormData(),
        ]);
    };

    const removeStep = (index: number) => {
        if (form.data.steps.length <= 1) {
            return;
        }

        form.setData(
            'steps',
            form.data.steps.filter((_, i) => i !== index),
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="flex w-full flex-col rounded-none glass-card p-0 sm:max-w-lg"
            >
                <SheetHeader className="border-b border-border/60 p-8 pb-6">
                    <SheetTitle className="text-xl font-bold tracking-tight">
                        {policy
                            ? 'Edit approval policy'
                            : 'New approval policy'}
                    </SheetTitle>
                    <SheetDescription className="mt-1 text-sm text-muted-foreground/80">
                        {policy
                            ? 'Update policy details and approval steps.'
                            : 'Define a multi-step leave approval chain.'}
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-8 overflow-y-auto p-8">
                    <div className="space-y-5">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label
                                    htmlFor="status"
                                    className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                                >
                                    Status
                                </Label>
                                <AppSelect
                                    value={form.data.status}
                                    onValueChange={(v) =>
                                        form.setData(
                                            'status',
                                            v as 'active' | 'inactive',
                                        )
                                    }
                                    variant="card"
                                >
                                    <AppSelectItem value="active">
                                        Active
                                    </AppSelectItem>
                                    <AppSelectItem value="inactive">
                                        Inactive
                                    </AppSelectItem>
                                </AppSelect>
                                {form.errors.status ? (
                                    <div className="text-xs font-medium text-destructive">
                                        {form.errors.status}
                                    </div>
                                ) : null}
                            </div>

                            <div className="flex items-end">
                                <div className="flex w-full items-center justify-between rounded-xl border border-border/60 px-4 py-3">
                                    <div>
                                        <p className="text-sm font-medium">
                                            Company default
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Use when no department policy
                                        </p>
                                    </div>
                                    <Switch
                                        checked={form.data.is_default}
                                        onCheckedChange={(v) =>
                                            form.setData('is_default', v)
                                        }
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="name"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Name
                            </Label>
                            <Input
                                id="name"
                                placeholder="Standard leave approval"
                                className={inputClass}
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.name}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <Label
                                htmlFor="description"
                                className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                            >
                                Description
                            </Label>
                            <Textarea
                                id="description"
                                placeholder="Optional notes about this policy..."
                                className="min-h-24 rounded-xl border-border bg-card focus-visible:ring-primary/40"
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                            />
                            {form.errors.description ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.description}
                                </div>
                            ) : null}
                        </div>

                        <div className="space-y-3">
                            <div className="flex items-center justify-between gap-3">
                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                    Approval steps
                                </Label>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    className="h-8 rounded-lg"
                                    onClick={addStep}
                                >
                                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                                    Add step
                                </Button>
                            </div>

                            {form.errors.steps ? (
                                <div className="text-xs font-medium text-destructive">
                                    {form.errors.steps}
                                </div>
                            ) : null}

                            <div className="space-y-4">
                                {form.data.steps.map((step, index) => {
                                    const typeMeta = approverTypeMeta(
                                        approverTypes,
                                        step.approver_type,
                                    );
                                    const showEmployee =
                                        typeMeta?.requires_employee ||
                                        typeMeta?.allows_employee_override;

                                    return (
                                        <div
                                            key={step.id ?? `new-${index}`}
                                            className="space-y-4 rounded-xl border border-border/60 bg-muted/20 p-4 dark:border-white/6 dark:bg-white/3"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <div className="text-xs font-bold tracking-wider text-muted-foreground/70 uppercase">
                                                    Step {index + 1}
                                                </div>
                                                <div className="flex items-center gap-1">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-8 w-8 rounded-lg"
                                                        disabled={index === 0}
                                                        onClick={() =>
                                                            onMoveStep(
                                                                index,
                                                                'up',
                                                            )
                                                        }
                                                        title="Move up"
                                                    >
                                                        <ArrowUp className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-8 w-8 rounded-lg"
                                                        disabled={
                                                            index ===
                                                            form.data.steps
                                                                .length -
                                                                1
                                                        }
                                                        onClick={() =>
                                                            onMoveStep(
                                                                index,
                                                                'down',
                                                            )
                                                        }
                                                        title="Move down"
                                                    >
                                                        <ArrowDown className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-8 w-8 rounded-lg text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                        disabled={
                                                            form.data.steps
                                                                .length <= 1
                                                        }
                                                        onClick={() =>
                                                            removeStep(index)
                                                        }
                                                        title="Remove step"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </div>

                                            <div className="space-y-2">
                                                <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                                    Approver type
                                                </Label>
                                                <AppSelect
                                                    value={step.approver_type}
                                                    onValueChange={(v) => {
                                                        const nextMeta =
                                                            approverTypeMeta(
                                                                approverTypes,
                                                                v,
                                                            );
                                                        updateStep(index, {
                                                            approver_type: v,
                                                            approver_employee_id:
                                                                nextMeta?.requires_employee ||
                                                                nextMeta?.allows_employee_override
                                                                    ? step.approver_employee_id
                                                                    : '',
                                                        });
                                                    }}
                                                    variant="card"
                                                >
                                                    {approverTypes.map(
                                                        (type) => (
                                                            <AppSelectItem
                                                                key={type.value}
                                                                value={
                                                                    type.value
                                                                }
                                                            >
                                                                {type.label}
                                                            </AppSelectItem>
                                                        ),
                                                    )}
                                                </AppSelect>
                                                {form.errors[
                                                    `steps.${index}.approver_type`
                                                ] ? (
                                                    <div className="text-xs font-medium text-destructive">
                                                        {
                                                            form.errors[
                                                                `steps.${index}.approver_type`
                                                            ]
                                                        }
                                                    </div>
                                                ) : null}
                                            </div>

                                            {showEmployee ? (
                                                <div className="space-y-2">
                                                    <Label className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase">
                                                        {typeMeta?.requires_employee
                                                            ? 'Employee'
                                                            : 'Employee override (optional)'}
                                                    </Label>
                                                    <AppSelect
                                                        value={String(
                                                            step.approver_employee_id ??
                                                                '',
                                                        )}
                                                        onValueChange={(v) =>
                                                            updateStep(index, {
                                                                approver_employee_id:
                                                                    v
                                                                        ? Number(
                                                                              v,
                                                                          )
                                                                        : '',
                                                            })
                                                        }
                                                        variant="card"
                                                        placeholder={
                                                            typeMeta?.requires_employee
                                                                ? 'Select employee'
                                                                : 'Use company HR setting'
                                                        }
                                                    >
                                                        {!typeMeta?.requires_employee ? (
                                                            <AppSelectItem value="">
                                                                Use company HR
                                                                setting
                                                            </AppSelectItem>
                                                        ) : null}
                                                        {employees.map(
                                                            (employee) => (
                                                                <AppSelectItem
                                                                    key={
                                                                        employee.id
                                                                    }
                                                                    value={String(
                                                                        employee.id,
                                                                    )}
                                                                >
                                                                    {employee.employee_no
                                                                        ? `${employee.employee_no} — ${employee.name}`
                                                                        : (employee.name ??
                                                                          'Employee')}
                                                                    {employee.actionable ===
                                                                    false
                                                                        ? ' (not actionable)'
                                                                        : ''}
                                                                </AppSelectItem>
                                                            ),
                                                        )}
                                                    </AppSelect>
                                                    {form.errors[
                                                        `steps.${index}.approver_employee_id`
                                                    ] ? (
                                                        <div className="text-xs font-medium text-destructive">
                                                            {
                                                                form.errors[
                                                                    `steps.${index}.approver_employee_id`
                                                                ]
                                                            }
                                                        </div>
                                                    ) : null}
                                                    {(() => {
                                                        const selected =
                                                            employees.find(
                                                                (employee) =>
                                                                    employee.id ===
                                                                    Number(
                                                                        step.approver_employee_id,
                                                                    ),
                                                            );
                                                        if (
                                                            !selected ||
                                                            !selected.warnings
                                                                ?.length
                                                        ) {
                                                            return null;
                                                        }

                                                        return (
                                                            <div className="space-y-1 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200">
                                                                {selected.warnings.map(
                                                                    (
                                                                        warning,
                                                                    ) => (
                                                                        <p
                                                                            key={
                                                                                warning
                                                                            }
                                                                        >
                                                                            {
                                                                                warning
                                                                            }
                                                                        </p>
                                                                    ),
                                                                )}
                                                            </div>
                                                        );
                                                    })()}
                                                </div>
                                            ) : null}

                                            <div className="flex items-center justify-between rounded-xl border border-border/60 px-4 py-3">
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        Required
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Must approve before the
                                                        next step
                                                    </p>
                                                </div>
                                                <Switch
                                                    checked={step.is_required}
                                                    onCheckedChange={(v) =>
                                                        updateStep(index, {
                                                            is_required: v,
                                                        })
                                                    }
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="flex gap-3 border-t border-border/60 bg-background/40 p-6">
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-11 flex-1 rounded-xl px-6 text-muted-foreground"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        className="h-11 flex-1 rounded-xl px-6 font-semibold"
                        type="button"
                        onClick={onSubmit}
                        disabled={form.processing}
                    >
                        {policy ? 'Save' : 'Create'}
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
