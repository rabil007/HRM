import { useForm } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { useEffect, useState } from 'react';
import { ActionImpactPreview } from '@/components/action-impact-preview';
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
import {
    formatCompanyTimezoneLabel,
    isCompanyTimeInFuture,
    nowInCompanyTime,
    useCompanyTimezone,
} from '@/lib/company-timezone';
import { cn } from '@/lib/utils';
import {
    store as storeCorrection,
    override as overrideCorrection,
} from '@/routes/organization/crew-assignments/corrections';
import {
    CORRECTION_DATE_FIELDS,
    CORRECTION_SELECT_OPTIONS,
    buildCorrectionImpactChanges,
    editableCorrectionFields,
    initialCorrectionFieldValue,
    initialCorrectionValues,
} from '../lib/correction-form';
import type { CorrectablePhase, CrewAssignmentFormOptions } from '../types';

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
    companyTimezone,
    mode = 'request',
    initialPhaseId = null,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignmentId: number;
    correctablePhases: CorrectablePhase[];
    formOptions?: CrewAssignmentFormOptions;
    companyTimezone?: string;
    mode?: 'request' | 'override';
    initialPhaseId?: number | null;
}): ReactElement {
    const effectiveTimezone = useCompanyTimezone(companyTimezone);
    const timezoneLabel = formatCompanyTimezoneLabel(effectiveTimezone);
    const nowLocal = nowInCompanyTime(effectiveTimezone);

    const [step, setStep] = useState<1 | 2 | 3>(1);
    const [selectedPhaseId, setSelectedPhaseId] = useState<number | null>(null);

    const form = useForm<CorrectionFormData>({
        crew_assignment_phase_id: null,
        proposed_values: {},
        reason: '',
    });

    const selectedPhase =
        correctablePhases.find((phase) => phase.id === selectedPhaseId) ?? null;
    const editableFields = selectedPhase
        ? editableCorrectionFields(selectedPhase)
        : [];
    const currentCourse = selectedPhase?.current_values['details.course_id'];
    const currentCourseId = initialCorrectionFieldValue(
        'details.course_id',
        currentCourse,
        effectiveTimezone,
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        form.reset();
        form.clearErrors();

        if (initialPhaseId !== null) {
            const phase = correctablePhases.find(
                (p) => p.id === initialPhaseId,
            );

            if (phase && !phase.has_pending_correction) {
                setSelectedPhaseId(phase.id);
                form.setData({
                    crew_assignment_phase_id: phase.id,
                    proposed_values: initialCorrectionValues(
                        phase,
                        effectiveTimezone,
                    ),
                    reason: '',
                });
                setStep(2);

                return;
            }
        }

        setStep(1);
        setSelectedPhaseId(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset state when dialog opens
    }, [open, initialPhaseId]);

    const selectPhase = (phase: CorrectablePhase): void => {
        if (phase.has_pending_correction) {
            return;
        }

        setSelectedPhaseId(phase.id);
        form.setData({
            crew_assignment_phase_id: phase.id,
            proposed_values: initialCorrectionValues(phase, effectiveTimezone),
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

        const endpoint =
            mode === 'override'
                ? overrideCorrection.url(assignmentId)
                : storeCorrection.url(assignmentId);

        form.post(endpoint, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    const hasChanges = Boolean(
        selectedPhase &&
        editableFields.some(
            (field) =>
                (form.data.proposed_values[field] ?? '') !==
                initialCorrectionFieldValue(
                    field,
                    selectedPhase.current_values[field],
                    effectiveTimezone,
                ),
        ),
    );

    const hasFutureActualDate = Boolean(
        selectedPhase &&
        editableFields.some(
            (field) =>
                field.includes('actual') &&
                CORRECTION_DATE_FIELDS.has(field) &&
                isCompanyTimeInFuture(
                    form.data.proposed_values[field] ?? '',
                    effectiveTimezone,
                ),
        ),
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 overflow-hidden glass-card p-0 sm:max-w-lg">
                <DialogHeader className="shrink-0 space-y-1.5 border-b border-border/60 px-6 py-4 text-left">
                    <DialogTitle>
                        {mode === 'override'
                            ? 'Correct movement directly'
                            : 'Request movement correction'}
                    </DialogTitle>
                    <DialogDescription>
                        {step === 1
                            ? 'Select the recorded phase you want to correct.'
                            : step === 2
                              ? 'Update the fields that need correcting.'
                              : mode === 'override'
                                ? 'Explain why this direct correction is needed and review immediate impact.'
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
                                        {phase.legacy_context_label ? (
                                            <span className="text-xs text-muted-foreground">
                                                {phase.legacy_context_label}
                                            </span>
                                        ) : null}
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
                            {editableFields.map((field) => (
                                <div key={field} className="space-y-2">
                                    <Label htmlFor={`correction-${field}`}>
                                        {correctionFieldLabel(field)}
                                    </Label>
                                    {CORRECTION_DATE_FIELDS.has(field) ? (
                                        <div className="space-y-1">
                                            <Input
                                                id={`correction-${field}`}
                                                type="datetime-local"
                                                max={
                                                    field.includes('actual')
                                                        ? nowLocal
                                                        : undefined
                                                }
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
                                            <p className="text-xs text-muted-foreground">
                                                Recorded in company time:{' '}
                                                {timezoneLabel}
                                            </p>
                                            {field.includes('actual') &&
                                            isCompanyTimeInFuture(
                                                form.data.proposed_values[
                                                    field
                                                ] ?? '',
                                                effectiveTimezone,
                                            ) ? (
                                                <p className="text-xs font-medium text-destructive">
                                                    This timestamp is in the
                                                    future in company time (
                                                    {timezoneLabel}) and will be
                                                    rejected.
                                                </p>
                                            ) : null}
                                        </div>
                                    ) : field in CORRECTION_SELECT_OPTIONS &&
                                      (formOptions ||
                                          field === 'details.course_id') ? (
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
                                            {(
                                                formOptions?.[
                                                    CORRECTION_SELECT_OPTIONS[
                                                        field
                                                    ]
                                                ] ?? []
                                            ).map((option) => (
                                                <AppSelectItem
                                                    key={option.id}
                                                    value={String(option.id)}
                                                >
                                                    {option.name}
                                                </AppSelectItem>
                                            ))}
                                            {field === 'details.course_id' &&
                                            !formOptions?.courses.some(
                                                (course) =>
                                                    String(course.id) ===
                                                    currentCourseId,
                                            ) ? (
                                                <AppSelectItem
                                                    value={currentCourseId}
                                                    disabled
                                                >
                                                    {currentCourse?.display ??
                                                        currentCourseId}
                                                </AppSelectItem>
                                            ) : null}
                                        </AppSelect>
                                    ) : field.startsWith('details.') ||
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

                    {step === 3 && selectedPhase ? (
                        <div className="space-y-4">
                            <ActionImpactPreview
                                severity="high"
                                title={
                                    mode === 'override'
                                        ? 'Immediate override impact'
                                        : 'Correction summary'
                                }
                                subject={[
                                    selectedPhase.phase_code.toUpperCase(),
                                    selectedPhase.phase_label,
                                ].join(' · ')}
                                changes={buildCorrectionImpactChanges(
                                    selectedPhase,
                                    form.data.proposed_values,
                                    formOptions,
                                    effectiveTimezone,
                                )}
                                warning={
                                    mode === 'override'
                                        ? 'This privileged correction takes effect immediately and updates official movement records and dependent timelines without a separate approval step.'
                                        : 'This correction will be submitted for review. Official movement data will not change until an authorized approver approves it.'
                                }
                            />
                            <div className="space-y-2">
                                <Label htmlFor="correction-reason">
                                    Reason{' '}
                                    <span className="text-destructive">*</span>
                                </Label>
                                <Textarea
                                    id="correction-reason"
                                    value={form.data.reason}
                                    onChange={(event) =>
                                        form.setData(
                                            'reason',
                                            event.target.value,
                                        )
                                    }
                                    rows={4}
                                    required
                                    aria-required="true"
                                />
                                <InputError message={form.errors.reason} />
                            </div>
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
                                disabled={!hasChanges || hasFutureActualDate}
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
                                {mode === 'override'
                                    ? 'Apply Correction Immediately'
                                    : 'Submit Correction Request'}
                            </Button>
                        ) : null}
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
