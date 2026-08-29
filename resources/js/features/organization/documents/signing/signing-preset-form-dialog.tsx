import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import type {
    SigningPresetFormOptions,
    SigningPresetSummary,
} from '@/features/organization/documents/signing/types';
import { store, update } from '@/routes/organization/documents/signing-presets';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    preset: SigningPresetSummary | null;
    formOptions: SigningPresetFormOptions;
};

type RecipientRole = 'subject' | 'manager' | 'company_signatory';

type FormStep = {
    key: string;
    recipient_role: RecipientRole;
    step_label: string;
    target_user_id: string;
};

type FormData = {
    name: string;
    description: string;
    steps: FormStep[];
};

type SubmitStep = {
    recipient_role: RecipientRole;
    step_label?: string;
    target_user_id?: number;
};

const MAX_STEPS = 8;

function newStepKey(): string {
    return `step-${Math.random().toString(36).slice(2, 10)}`;
}

function defaultSubjectStep(): FormStep {
    return {
        key: newStepKey(),
        recipient_role: 'subject',
        step_label: '',
        target_user_id: '',
    };
}

function roleFallbackLabel(role: RecipientRole, occurrence: number): string {
    if (role === 'subject') {
        return 'Employee';
    }

    if (role === 'manager') {
        return occurrence === 1
            ? 'Department Manager'
            : `Management level ${occurrence}`;
    }

    return occurrence === 1
        ? 'Company Signatory'
        : `Company Signatory ${occurrence}`;
}

function stepPreviewLabel(step: FormStep, occurrence: number): string {
    const trimmed = step.step_label.trim();

    return trimmed !== ''
        ? trimmed
        : roleFallbackLabel(step.recipient_role, occurrence);
}

function stepsFromPreset(preset: SigningPresetSummary | null): FormStep[] {
    if (!preset || preset.steps.length === 0) {
        return [defaultSubjectStep()];
    }

    const mapped = preset.steps.map((step) => ({
        key: newStepKey(),
        recipient_role: step.recipient_role,
        step_label: step.step_label ?? '',
        target_user_id: step.target_user_id ? String(step.target_user_id) : '',
    }));

    if (mapped[0]?.recipient_role !== 'subject') {
        return [defaultSubjectStep(), ...mapped];
    }

    return mapped;
}

function toSubmitSteps(steps: FormStep[]): SubmitStep[] {
    return steps.map((step) => {
        const payload: SubmitStep = {
            recipient_role: step.recipient_role,
        };
        const label = step.step_label.trim();

        if (label !== '') {
            payload.step_label = label;
        }

        if (
            step.recipient_role === 'company_signatory' &&
            step.target_user_id !== ''
        ) {
            payload.target_user_id = Number(step.target_user_id);
        }

        return payload;
    });
}

function fieldError(
    errors: Record<string, string>,
    key: string,
): string | undefined {
    return errors[key];
}

export function SigningPresetFormDialog({
    open,
    onOpenChange,
    preset,
    formOptions,
}: Props) {
    const form = useForm<FormData>({
        name: '',
        description: '',
        steps: [defaultSubjectStep()],
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        form.setData({
            name: preset?.name ?? '',
            description: preset?.description ?? '',
            steps: stepsFromPreset(preset),
        });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset only when dialog opens/preset changes
    }, [open, preset?.id]);

    const companyCount = form.data.steps.filter(
        (step) => step.recipient_role === 'company_signatory',
    ).length;
    const atMaxSteps = form.data.steps.length >= MAX_STEPS;
    const canAddManager = !atMaxSteps && companyCount === 0;
    const canAddCompany = !atMaxSteps;

    const sequencePreview = useMemo(() => {
        let managerOccurrence = 0;
        let companyOccurrence = 0;

        return form.data.steps
            .map((step) => {
                const occurrence =
                    step.recipient_role === 'manager'
                        ? ++managerOccurrence
                        : step.recipient_role === 'company_signatory'
                          ? ++companyOccurrence
                          : 1;

                return stepPreviewLabel(step, occurrence);
            })
            .join(' → ');
    }, [form.data.steps]);

    function updateStep(
        index: number,
        patch: Partial<Pick<FormStep, 'step_label' | 'target_user_id'>>,
    ): void {
        form.setData(
            'steps',
            form.data.steps.map((step, stepIndex) =>
                stepIndex === index ? { ...step, ...patch } : step,
            ),
        );
    }

    function addManagerStep(): void {
        if (!canAddManager) {
            return;
        }

        const subject = form.data.steps[0] ?? defaultSubjectStep();
        const managers = form.data.steps.filter(
            (step) => step.recipient_role === 'manager',
        );
        const companies = form.data.steps.filter(
            (step) => step.recipient_role === 'company_signatory',
        );

        form.setData('steps', [
            subject,
            ...managers,
            {
                key: newStepKey(),
                recipient_role: 'manager',
                step_label: '',
                target_user_id: '',
            },
            ...companies,
        ]);
    }

    function addCompanyStep(): void {
        if (!canAddCompany) {
            return;
        }

        form.setData('steps', [
            ...form.data.steps,
            {
                key: newStepKey(),
                recipient_role: 'company_signatory',
                step_label: '',
                target_user_id: '',
            },
        ]);
    }

    function removeStep(index: number): void {
        const step = form.data.steps[index];

        if (!step || step.recipient_role === 'subject') {
            return;
        }

        form.setData(
            'steps',
            form.data.steps.filter((_, stepIndex) => stepIndex !== index),
        );
    }

    function submit(): void {
        const payload = {
            name: form.data.name,
            description: form.data.description || null,
            steps: toSubmitSteps(form.data.steps),
        };

        form.transform(() => payload);

        if (preset) {
            form.put(update.url(preset.id), {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => form.transform((data) => data),
            });

            return;
        }

        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => form.transform((data) => data),
        });
    }

    const errors = form.errors as Record<string, string>;
    let managerOccurrence = 0;
    let companyOccurrence = 0;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col sm:max-w-lg">
                <SheetHeader>
                    <SheetTitle>
                        {preset ? 'Edit signing preset' : 'New signing preset'}
                    </SheetTitle>
                    <SheetDescription>
                        Build a sequential signing chain. Employee always signs
                        first, then management, then company signatories.
                    </SheetDescription>
                </SheetHeader>

                <div className="mt-6 flex-1 space-y-5 overflow-y-auto pb-4">
                    <div className="space-y-2">
                        <Label htmlFor="signing-preset-name">Name</Label>
                        <Input
                            id="signing-preset-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                        />
                        {form.errors.name ? (
                            <p className="text-sm text-destructive">
                                {form.errors.name}
                            </p>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="signing-preset-description">
                            Description
                        </Label>
                        <Textarea
                            id="signing-preset-description"
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </div>

                    <div className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <Label>Signing steps</Label>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={!canAddManager}
                                    onClick={addManagerStep}
                                >
                                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                                    Add management signer
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={!canAddCompany}
                                    onClick={addCompanyStep}
                                >
                                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                                    Add company signatory
                                </Button>
                            </div>
                        </div>

                        <p className="text-xs text-muted-foreground">
                            Up to {MAX_STEPS} steps. Management signers must
                            come before company signatories (
                            {form.data.steps.length}/{MAX_STEPS}).
                        </p>

                        <div className="space-y-3">
                            {form.data.steps.map((step, index) => {
                                const occurrence =
                                    step.recipient_role === 'manager'
                                        ? ++managerOccurrence
                                        : step.recipient_role ===
                                            'company_signatory'
                                          ? ++companyOccurrence
                                          : 1;
                                const stepError = fieldError(
                                    errors,
                                    `steps.${index}`,
                                );
                                const roleError = fieldError(
                                    errors,
                                    `steps.${index}.recipient_role`,
                                );
                                const labelError = fieldError(
                                    errors,
                                    `steps.${index}.step_label`,
                                );
                                const userError = fieldError(
                                    errors,
                                    `steps.${index}.target_user_id`,
                                );

                                if (step.recipient_role === 'subject') {
                                    return (
                                        <div
                                            key={step.key}
                                            className="rounded-lg border border-border/70 bg-muted/20 p-4"
                                        >
                                            <p className="font-medium">
                                                Step 1 — Employee (subject)
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                Always required as the first
                                                signer.
                                            </p>
                                        </div>
                                    );
                                }

                                if (step.recipient_role === 'manager') {
                                    return (
                                        <div
                                            key={step.key}
                                            className="space-y-3 rounded-lg border border-border/70 p-4"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="font-medium">
                                                        Step {index + 1} —
                                                        Management level{' '}
                                                        {occurrence}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        Resolved from the
                                                        employee management
                                                        hierarchy at flow start.
                                                    </p>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        removeStep(index)
                                                    }
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                            <div className="space-y-2">
                                                <Label
                                                    htmlFor={`signing-step-label-${step.key}`}
                                                >
                                                    Step label (optional)
                                                </Label>
                                                <Input
                                                    id={`signing-step-label-${step.key}`}
                                                    value={step.step_label}
                                                    placeholder={roleFallbackLabel(
                                                        'manager',
                                                        occurrence,
                                                    )}
                                                    onChange={(event) =>
                                                        updateStep(index, {
                                                            step_label:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                />
                                                {labelError ? (
                                                    <p className="text-sm text-destructive">
                                                        {labelError}
                                                    </p>
                                                ) : null}
                                            </div>
                                            {stepError || roleError ? (
                                                <p className="text-sm text-destructive">
                                                    {stepError ?? roleError}
                                                </p>
                                            ) : null}
                                        </div>
                                    );
                                }

                                return (
                                    <div
                                        key={step.key}
                                        className="space-y-3 rounded-lg border border-border/70 p-4"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="font-medium">
                                                    Step {index + 1} — Company
                                                    signatory
                                                    {occurrence > 1
                                                        ? ` ${occurrence}`
                                                        : ''}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    Select an eligible company
                                                    user.
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                onClick={() =>
                                                    removeStep(index)
                                                }
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </div>
                                        <div className="space-y-2">
                                            <Label
                                                htmlFor={`signing-step-label-${step.key}`}
                                            >
                                                Step label (optional)
                                            </Label>
                                            <Input
                                                id={`signing-step-label-${step.key}`}
                                                value={step.step_label}
                                                placeholder={roleFallbackLabel(
                                                    'company_signatory',
                                                    occurrence,
                                                )}
                                                onChange={(event) =>
                                                    updateStep(index, {
                                                        step_label:
                                                            event.target.value,
                                                    })
                                                }
                                            />
                                            {labelError ? (
                                                <p className="text-sm text-destructive">
                                                    {labelError}
                                                </p>
                                            ) : null}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Signatory user</Label>
                                            <AppSelect
                                                value={
                                                    step.target_user_id ||
                                                    '__none__'
                                                }
                                                onValueChange={(value) =>
                                                    updateStep(index, {
                                                        target_user_id:
                                                            value === '__none__'
                                                                ? ''
                                                                : value,
                                                    })
                                                }
                                            >
                                                <AppSelectItem value="__none__">
                                                    Select signatory
                                                </AppSelectItem>
                                                {formOptions.users.map(
                                                    (user) => (
                                                        <AppSelectItem
                                                            key={user.id}
                                                            value={String(
                                                                user.id,
                                                            )}
                                                        >
                                                            {user.name}
                                                        </AppSelectItem>
                                                    ),
                                                )}
                                            </AppSelect>
                                            {userError ? (
                                                <p className="text-sm text-destructive">
                                                    {userError}
                                                </p>
                                            ) : null}
                                        </div>
                                        {stepError || roleError ? (
                                            <p className="text-sm text-destructive">
                                                {stepError ?? roleError}
                                            </p>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <div className="rounded-lg bg-muted/40 p-3 text-sm">
                        <p className="font-medium">Sequence</p>
                        <p className="mt-1 text-muted-foreground">
                            {sequencePreview}
                        </p>
                    </div>

                    {errors.steps ? (
                        <p className="text-sm text-destructive">
                            {errors.steps}
                        </p>
                    ) : null}
                </div>

                <SheetFooter className="mt-4">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={form.processing}
                        onClick={submit}
                    >
                        {preset ? 'Save changes' : 'Create preset'}
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
