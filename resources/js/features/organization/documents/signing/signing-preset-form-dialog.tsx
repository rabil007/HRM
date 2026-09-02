import { useForm } from '@inertiajs/react';
import { ChevronRight, Lock, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import type { ReactNode } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Badge } from '@/components/ui/badge';
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
import { cn } from '@/lib/utils';
import { store, update } from '@/routes/organization/documents/signing-presets';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    preset: SigningPresetSummary | null;
    formOptions: SigningPresetFormOptions;
    preserveDesignerState?: boolean;
    onCreated?: (name: string) => void;
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

    // Use raw custom step_label only — never seed generated display_label into the input.
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
    preserveDesignerState = false,
    onCreated,
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

    const sequenceLabels = useMemo(() => {
        let managerOccurrence = 0;
        let companyOccurrence = 0;

        return form.data.steps.map((step) => {
            const occurrence =
                step.recipient_role === 'manager'
                    ? ++managerOccurrence
                    : step.recipient_role === 'company_signatory'
                      ? ++companyOccurrence
                      : 1;

            return stepPreviewLabel(step, occurrence);
        });
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
                preserveState: preserveDesignerState,
                onSuccess: () => onOpenChange(false),
                onFinish: () => form.transform((data) => data),
            });

            return;
        }

        form.post(store.url(), {
            preserveScroll: true,
            preserveState: preserveDesignerState,
            onSuccess: () => {
                onCreated?.(form.data.name);
                onOpenChange(false);
            },
            onFinish: () => form.transform((data) => data),
        });
    }

    const errors = form.errors as Record<string, string>;
    let managerOccurrence = 0;
    let companyOccurrence = 0;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-lg">
                <SheetHeader className="border-b border-border/60 px-6 py-5 pr-12">
                    <SheetTitle>
                        {preset ? 'Edit signing preset' : 'New signing preset'}
                    </SheetTitle>
                    <SheetDescription>
                        Employee signs first, then managers, then company
                        signatories.
                    </SheetDescription>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto px-6 py-5">
                    <section className="space-y-3">
                        <div className="space-y-1.5">
                            <Label
                                htmlFor="signing-preset-name"
                                className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Name
                            </Label>
                            <Input
                                id="signing-preset-name"
                                value={form.data.name}
                                placeholder="e.g. Offer letter signing"
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                            {form.errors.name ? (
                                <p className="text-xs text-destructive">
                                    {form.errors.name}
                                </p>
                            ) : null}
                        </div>

                        <div className="space-y-1.5">
                            <Label
                                htmlFor="signing-preset-description"
                                className="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                Description
                                <span className="ml-1 font-normal tracking-normal text-muted-foreground/70 normal-case">
                                    optional
                                </span>
                            </Label>
                            <Textarea
                                id="signing-preset-description"
                                value={form.data.description}
                                rows={2}
                                placeholder="When this signing chain is used"
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                    </section>

                    <section className="space-y-3">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    Signing chain
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Add managers before company signatories.
                                </p>
                            </div>
                            <Badge variant="outline">
                                {form.data.steps.length}/{MAX_STEPS}
                            </Badge>
                        </div>

                        <SequenceChips labels={sequenceLabels} />

                        <div className="space-y-0">
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
                                const isLast =
                                    index === form.data.steps.length - 1;

                                if (step.recipient_role === 'subject') {
                                    return (
                                        <SigningStepFrame
                                            key={step.key}
                                            index={index}
                                            isLast={isLast}
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <p className="text-sm font-medium">
                                                            Employee
                                                        </p>
                                                        <Badge variant="secondary">
                                                            Required
                                                        </Badge>
                                                    </div>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        Always the first signer.
                                                    </p>
                                                </div>
                                                <Lock
                                                    className="mt-0.5 size-3.5 text-muted-foreground"
                                                    aria-hidden
                                                />
                                            </div>
                                        </SigningStepFrame>
                                    );
                                }

                                if (step.recipient_role === 'manager') {
                                    return (
                                        <SigningStepFrame
                                            key={step.key}
                                            index={index}
                                            isLast={isLast}
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-medium">
                                                        Management level{' '}
                                                        {occurrence}
                                                    </p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        Resolved from the
                                                        employee hierarchy when
                                                        the flow starts.
                                                    </p>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                                    onClick={() =>
                                                        removeStep(index)
                                                    }
                                                    aria-label={`Remove management level ${occurrence}`}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label
                                                    htmlFor={`signing-step-label-${step.key}`}
                                                    className="text-xs text-muted-foreground"
                                                >
                                                    Step label
                                                    <span className="ml-1 text-muted-foreground/70">
                                                        optional
                                                    </span>
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
                                                    <p className="text-xs text-destructive">
                                                        {labelError}
                                                    </p>
                                                ) : null}
                                            </div>
                                            {stepError || roleError ? (
                                                <p className="text-xs text-destructive">
                                                    {stepError ?? roleError}
                                                </p>
                                            ) : null}
                                        </SigningStepFrame>
                                    );
                                }

                                return (
                                    <SigningStepFrame
                                        key={step.key}
                                        index={index}
                                        isLast={isLast}
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-medium">
                                                    Company signatory
                                                    {occurrence > 1
                                                        ? ` ${occurrence}`
                                                        : ''}
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    Choose a specific company
                                                    user.
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                                onClick={() =>
                                                    removeStep(index)
                                                }
                                                aria-label={`Remove company signatory ${occurrence}`}
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label
                                                htmlFor={`signing-step-label-${step.key}`}
                                                className="text-xs text-muted-foreground"
                                            >
                                                Step label
                                                <span className="ml-1 text-muted-foreground/70">
                                                    optional
                                                </span>
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
                                                <p className="text-xs text-destructive">
                                                    {labelError}
                                                </p>
                                            ) : null}
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label className="text-xs text-muted-foreground">
                                                Signatory user
                                            </Label>
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
                                                <p className="text-xs text-destructive">
                                                    {userError}
                                                </p>
                                            ) : null}
                                        </div>
                                        {stepError || roleError ? (
                                            <p className="text-xs text-destructive">
                                                {stepError ?? roleError}
                                            </p>
                                        ) : null}
                                    </SigningStepFrame>
                                );
                            })}
                        </div>

                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={!canAddManager}
                                onClick={addManagerStep}
                            >
                                <Plus className="size-3.5" />
                                Add manager
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={!canAddCompany}
                                onClick={addCompanyStep}
                            >
                                <Plus className="size-3.5" />
                                Add company signatory
                            </Button>
                        </div>
                    </section>

                    {errors.steps ? (
                        <p className="text-xs text-destructive">
                            {errors.steps}
                        </p>
                    ) : null}
                </div>

                <SheetFooter className="flex-row justify-end gap-2 border-t border-border/60 px-6 py-4">
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

function SequenceChips({ labels }: { labels: string[] }) {
    return (
        <div
            className="flex flex-wrap items-center gap-1.5 rounded-xl border border-border/60 bg-muted/20 px-3 py-2"
            aria-label={`Signing sequence: ${labels.join(', then ')}`}
        >
            {labels.map((label, index) => (
                <span
                    key={`${label}-${index}`}
                    className="flex items-center gap-1.5"
                >
                    {index > 0 ? (
                        <ChevronRight
                            className="size-3.5 text-muted-foreground/70"
                            aria-hidden
                        />
                    ) : null}
                    <span className="rounded-full border border-border/70 bg-background px-2 py-0.5 text-[11px] font-medium">
                        {label}
                    </span>
                </span>
            ))}
        </div>
    );
}

function SigningStepFrame({
    index,
    isLast,
    children,
}: {
    index: number;
    isLast: boolean;
    children: ReactNode;
}) {
    return (
        <div className="relative pl-8">
            {!isLast ? (
                <div
                    className="absolute top-7 bottom-0 left-2.75 w-px bg-border"
                    aria-hidden
                />
            ) : null}
            <div className="absolute top-3 left-0 flex size-6 items-center justify-center rounded-full border border-border bg-background text-[11px] font-semibold">
                {index + 1}
            </div>
            <div
                className={cn(
                    'space-y-3 rounded-xl border border-border/70 bg-card p-3.5',
                    !isLast && 'mb-3',
                )}
            >
                {children}
            </div>
        </div>
    );
}
