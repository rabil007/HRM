import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useEffect, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { correctionFieldLabel } from '@/features/organization/crew-movement-corrections/types';
import type { CrewMovementCorrectionFieldValue } from '@/features/organization/crew-movement-corrections/types';
import { cn } from '@/lib/utils';
import { store as storeCorrection } from '@/routes/organization/crew-assignments/corrections';
import type { CorrectablePhase, CrewAssignmentFormOptions } from '../types';

const ASSIGNMENT_FIELD_OPTIONS: Record<
    string,
    keyof CrewAssignmentFormOptions
> = {
    vessel_id: 'vessels',
    rank_id: 'ranks',
    client_id: 'clients',
    company_visa_type_id: 'visa_types',
};

const DATE_FIELDS = new Set(['actual_start_at', 'actual_end_at']);

function isAssignmentField(field: string): boolean {
    return field in ASSIGNMENT_FIELD_OPTIONS;
}

function isDetailsField(field: string): boolean {
    return field.startsWith('details.');
}

function toDatetimeLocalValue(display: string | null | undefined): string {
    if (!display) {
        return '';
    }

    return display.replace(' ', 'T');
}

function initialFieldValue(
    field: string,
    current: CrewMovementCorrectionFieldValue | undefined,
): string {
    if (!current) {
        return '';
    }

    if (DATE_FIELDS.has(field)) {
        return toDatetimeLocalValue(current.display);
    }

    if (isAssignmentField(field)) {
        return current.value === null || current.value === undefined
            ? ''
            : String(current.value);
    }

    return current.display ?? '';
}

type CorrectionFormData = {
    crew_assignment_phase_id: number | null;
    proposed_values: Record<string, string>;
    reason: string;
};

export function RequestCorrectionDialog({
    open,
    onOpenChange,
    assignmentId,
    correctablePhases,
    formOptions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignmentId: number;
    correctablePhases: CorrectablePhase[];
    formOptions?: CrewAssignmentFormOptions;
}): ReactElement {
    const [step, setStep] = useState<1 | 2 | 3>(1);
    const [selectedPhaseId, setSelectedPhaseId] = useState<number | null>(null);

    const form = useForm<CorrectionFormData>({
        crew_assignment_phase_id: null,
        proposed_values: {},
        reason: '',
    });

    const selectedPhase =
        correctablePhases.find((phase) => phase.id === selectedPhaseId) ?? null;

    useEffect(() => {
        if (!open) {
            return;
        }

        setStep(1);
        setSelectedPhaseId(null);
        form.reset();
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset state when dialog opens
    }, [open]);

    const selectPhase = (phase: CorrectablePhase): void => {
        if (phase.has_pending_correction) {
            return;
        }

        const initialValues: Record<string, string> = {};

        phase.allowed_fields.forEach((field) => {
            initialValues[field] = initialFieldValue(
                field,
                phase.current_values[field],
            );
        });

        setSelectedPhaseId(phase.id);
        form.setData({
            crew_assignment_phase_id: phase.id,
            proposed_values: initialValues,
            reason: '',
        });
        setStep(2);
    };

    const setFieldValue = (field: string, value: string): void => {
        form.setData('proposed_values', {
            ...form.data.proposed_values,
            [field]: value,
        });
    };

    const goBack = (): void => {
        if (step === 2) {
            setStep(1);
            setSelectedPhaseId(null);

            return;
        }

        if (step === 3) {
            setStep(2);
        }
    };

    const submit = (): void => {
        if (!selectedPhase) {
            return;
        }

        form.post(storeCorrection.url(assignmentId), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const hasChanges = Boolean(
        selectedPhase &&
        selectedPhase.allowed_fields.some(
            (field) =>
                (form.data.proposed_values[field] ?? '') !==
                initialFieldValue(field, selectedPhase.current_values[field]),
        ),
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden glass-card p-0 sm:max-w-lg">
                <DialogHeader className="shrink-0 space-y-1.5 border-b border-border/60 px-6 py-4 text-left">
                    <DialogTitle>Request movement correction</DialogTitle>
                    <DialogDescription>
                        {step === 1
                            ? 'Select the recorded phase you want to correct.'
                            : step === 2
                              ? 'Update the fields that need correcting.'
                              : 'Explain why this correction is needed.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4">
                    <InputError
                        message={
                            (form.errors as Record<string, string | undefined>)
                                .correction
                        }
                    />

                    {step === 1 ? (
                        correctablePhases.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No recorded phases are available for correction.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {correctablePhases.map((phase) => (
                                    <button
                                        key={phase.id}
                                        type="button"
                                        disabled={phase.has_pending_correction}
                                        onClick={() => selectPhase(phase)}
                                        className={cn(
                                            'flex w-full flex-col gap-1 rounded-lg border p-3 text-left transition-colors',
                                            phase.has_pending_correction
                                                ? 'cursor-not-allowed border-border/40 bg-muted/20 opacity-60'
                                                : 'border-border hover:border-primary/50 hover:bg-accent',
                                        )}
                                    >
                                        <span className="text-sm font-semibold">
                                            {phase.phase_code.toUpperCase()} ·{' '}
                                            {phase.phase_label}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {phase.status_label}
                                            {phase.has_pending_correction
                                                ? ' · Correction already pending'
                                                : ''}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )
                    ) : null}

                    {step === 2 && selectedPhase ? (
                        <div className="space-y-4">
                            {selectedPhase.allowed_fields.map((field) => (
                                <div key={field} className="space-y-2">
                                    <Label htmlFor={`correction-${field}`}>
                                        {correctionFieldLabel(field)}
                                    </Label>
                                    {DATE_FIELDS.has(field) ? (
                                        <Input
                                            id={`correction-${field}`}
                                            type="datetime-local"
                                            value={
                                                form.data.proposed_values[
                                                    field
                                                ] ?? ''
                                            }
                                            onChange={(event) =>
                                                setFieldValue(
                                                    field,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    ) : isAssignmentField(field) &&
                                      formOptions ? (
                                        <AppSelect
                                            value={
                                                form.data.proposed_values[
                                                    field
                                                ] ?? ''
                                            }
                                            onValueChange={(value) =>
                                                setFieldValue(field, value)
                                            }
                                            variant="card"
                                            placeholder="Select..."
                                        >
                                            {formOptions[
                                                ASSIGNMENT_FIELD_OPTIONS[field]
                                            ].map((option) => (
                                                <AppSelectItem
                                                    key={option.id}
                                                    value={String(option.id)}
                                                >
                                                    {option.name}
                                                </AppSelectItem>
                                            ))}
                                        </AppSelect>
                                    ) : isDetailsField(field) ||
                                      field === 'remarks' ? (
                                        <Textarea
                                            id={`correction-${field}`}
                                            value={
                                                form.data.proposed_values[
                                                    field
                                                ] ?? ''
                                            }
                                            onChange={(event) =>
                                                setFieldValue(
                                                    field,
                                                    event.target.value,
                                                )
                                            }
                                            rows={2}
                                        />
                                    ) : (
                                        <Input
                                            id={`correction-${field}`}
                                            value={
                                                form.data.proposed_values[
                                                    field
                                                ] ?? ''
                                            }
                                            onChange={(event) =>
                                                setFieldValue(
                                                    field,
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : null}

                    {step === 3 ? (
                        <div className="space-y-2">
                            <Label htmlFor="correction-reason">
                                Reason{' '}
                                <span className="text-destructive">*</span>
                            </Label>
                            <Textarea
                                id="correction-reason"
                                value={form.data.reason}
                                onChange={(event) =>
                                    form.setData('reason', event.target.value)
                                }
                                rows={4}
                                required
                                aria-required="true"
                            />
                            <InputError message={form.errors.reason} />
                        </div>
                    ) : null}
                </div>

                <DialogFooter className="shrink-0 border-t border-border/60 px-6 py-4 sm:justify-between">
                    <div>
                        {step > 1 ? (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={goBack}
                                disabled={form.processing}
                            >
                                Back
                            </Button>
                        ) : null}
                    </div>
                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        {step === 2 ? (
                            <Button
                                type="button"
                                onClick={() => setStep(3)}
                                disabled={!hasChanges}
                            >
                                Next
                            </Button>
                        ) : null}
                        {step === 3 ? (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={
                                    form.processing || !form.data.reason.trim()
                                }
                            >
                                {form.processing ? (
                                    <Spinner className="mr-2" />
                                ) : null}
                                Submit request
                            </Button>
                        ) : null}
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
