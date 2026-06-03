import { router } from '@inertiajs/react';
import { FileText, UploadCloud } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import type { ReactElement } from 'react';
import {
    store as storeTraining,
    update as updateTraining,
} from '@/actions/App/Http/Controllers/Organization/EmployeeTrainingController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { resolveEmployeeIdForSave } from '@/features/organization/employees/profile/resolve-employee-id-for-save';
import type { CountryOption } from '@/features/organization/employees/types';
import { AddTrainingCertificateListItem } from '@/features/organization/training/add-training/add-training-certificate-list-item';
import {
    AddTrainingDraftForm,
    CERTIFICATE_TEMPLATE_FIELD,
} from '@/features/organization/training/add-training/add-training-draft-form';
import {
    buildTrainingSubmitPayload,
    copyTrainingMetadataFromSource,
    createTrainingDraftFromFile,
    createUploadDraftId,
    fileMatchesExistingDraft,
    formatUploadFileSize,
    hasVisibleTrainingContent,
    MAX_TRAINING_CERTIFICATE_FILES,
    SUPPORTED_UPLOAD_MIME_TYPES,
    trainingDraftToFormData,
    trainingMetadataFromItem,
} from '@/features/organization/training/add-training/training-draft';
import type {
    TrainingDraft,
    TrainingDraftFieldErrors,
    TrainingDraftMetadata,
} from '@/features/organization/training/add-training/training-draft';
import { actions } from '@/lib/design-system';
import { toast } from '@/lib/toast';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
import {
    useClearMissingOnFormChange,
    useTemplateRecordFields,
} from '@/pages/organization/_hooks/use-template-record-fields';
import {
    getTemplateRequiredFieldKeys,
    isEmptyTemplateFieldValue,
} from '@/pages/organization/_lib/template-field-visibility';
import { TEMPLATE_RECORD_DEFAULT_REQUIRED } from '@/pages/organization/_lib/template-record-defaults';
import type {
    CourseOption,
    TemplateFieldConfig,
    TrainingItem,
} from '@/pages/organization/employee-page.types';

const TRAINING_RELOAD = {
    preserveScroll: true,
    only: ['trainings'],
} as const;

function emptyTrainingMetadata(): TrainingDraftMetadata {
    return {
        course_id: '',
        issue_date: '',
        expiry_date: '',
        institute_center: '',
        country_id: '',
    };
}

function parseTrainingFieldErrors(
    errors: Record<string, string | string[]>,
): TrainingDraftFieldErrors {
    const mapped: TrainingDraftFieldErrors = {};

    for (const [key, rawValue] of Object.entries(errors)) {
        const message = Array.isArray(rawValue) ? (rawValue[0] ?? '') : rawValue;

        if (!message) {
            continue;
        }

        if (key === 'certificate') {
            mapped.certificate = message;
        } else if (key in emptyTrainingMetadata()) {
            mapped[key as keyof TrainingDraftMetadata] = message;
        }
    }

    return mapped;
}

export function AddTrainingDialog({
    open,
    onOpenChange,
    employeeId,
    employeeName,
    ensureEmployee,
    courses,
    countries,
    templateFields = null,
    editingTraining = null,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number | null;
    employeeName: string;
    ensureEmployee?: () => Promise<number>;
    courses: CourseOption[];
    countries: CountryOption[];
    templateFields?: Record<string, TemplateFieldConfig> | null;
    editingTraining?: TrainingItem | null;
}): ReactElement {
    const isEdit = editingTraining !== null;

    const {
        showField,
        isFieldRequired,
        isMissingRequired,
        missingRequiredFieldsList,
        clearMissingRequired,
        focusMissingField,
        validateRequired,
        syncMissingFromFormData,
    } = useTemplateRecordFields(templateFields, {
        defaultRequiredFields: TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_trainings,
    });

    const showCertificatePanel = showField(CERTIFICATE_TEMPLATE_FIELD);

    const requiredFields = useMemo(
        () =>
            getTemplateRequiredFieldKeys(
                templateFields,
                TEMPLATE_RECORD_DEFAULT_REQUIRED.employee_trainings,
            ),
        [templateFields],
    );

    const [drafts, setDrafts] = useState<TrainingDraft[]>([]);
    const [selectedDraftId, setSelectedDraftId] = useState<string | null>(null);
    const [standaloneMetadata, setStandaloneMetadata] =
        useState<TrainingDraftMetadata>(emptyTrainingMetadata);
    const [fieldErrors, setFieldErrors] = useState<TrainingDraftFieldErrors>({});
    const [isDraggingFiles, setIsDraggingFiles] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [removeCertificate, setRemoveCertificate] = useState(false);

    const selectedDraft = useMemo(
        () => drafts.find((draft) => draft.id === selectedDraftId) ?? null,
        [drafts, selectedDraftId],
    );

    const activeMetadata = selectedDraft ?? standaloneMetadata;

    const activeFormData = useMemo(() => {
        const existingCertificate =
            isEdit && !removeCertificate && editingTraining?.certificate_url
                ? 'existing'
                : null;

        return trainingDraftToFormData(
            activeMetadata,
            selectedDraft?.file ?? null,
            existingCertificate,
        );
    }, [
        activeMetadata,
        editingTraining?.certificate_url,
        isEdit,
        removeCertificate,
        selectedDraft?.file,
    ]);

    useClearMissingOnFormChange(activeFormData, syncMissingFromFormData);

    const resetDialog = useCallback(() => {
        setDrafts([]);
        setSelectedDraftId(null);
        setStandaloneMetadata(emptyTrainingMetadata());
        setFieldErrors({});
        setIsDraggingFiles(false);
        setIsSaving(false);
        setRemoveCertificate(false);
        clearMissingRequired();
    }, [clearMissingRequired]);

    const initializeForOpen = useCallback(() => {
        if (isEdit && editingTraining) {
            setStandaloneMetadata(trainingMetadataFromItem(editingTraining));
            setDrafts([]);
            setSelectedDraftId(null);
            setRemoveCertificate(false);
            setFieldErrors({});
            clearMissingRequired();

            return;
        }

        resetDialog();
    }, [clearMissingRequired, editingTraining, isEdit, resetDialog]);

    const draftMeetsRequired = useCallback(
        (metadata: TrainingDraftMetadata, certificate: File | null): boolean => {
            const data = trainingDraftToFormData(
                metadata,
                certificate,
                isEdit && !removeCertificate && editingTraining?.certificate_url
                    ? 'existing'
                    : null,
            );

            for (const field of requiredFields) {
                if (!showField(field)) {
                    continue;
                }

                if (isEmptyTemplateFieldValue(data[field])) {
                    return false;
                }
            }

            return true;
        },
        [editingTraining?.certificate_url, isEdit, removeCertificate, requiredFields, showField],
    );

    const addCertificateFiles = useCallback(
        (files: File[]) => {
            const supportedFiles = files.filter((file) =>
                (SUPPORTED_UPLOAD_MIME_TYPES as readonly string[]).includes(file.type),
            );

            if (supportedFiles.length !== files.length) {
                toast.error('Only PDF, JPG, JPEG, and PNG files are supported.');
            }

            setDrafts((current) => {
                const next = [...current];
                let addedId: string | null = null;

                for (const file of supportedFiles) {
                    if (next.length >= MAX_TRAINING_CERTIFICATE_FILES) {
                        toast.error(
                            `You can add up to ${MAX_TRAINING_CERTIFICATE_FILES} certificates at once.`,
                        );

                        break;
                    }

                    if (fileMatchesExistingDraft(next, file)) {
                        continue;
                    }

                    const draft = {
                        ...createTrainingDraftFromFile(file),
                        ...copyTrainingMetadataFromSource(activeMetadata),
                    };
                    next.push(draft);
                    addedId = draft.id;
                }

                if (addedId) {
                    setSelectedDraftId(addedId);
                }

                return next;
            });
        },
        [activeMetadata],
    );

    const removeDraft = useCallback((draftId: string) => {
        setDrafts((current) => {
            const next = current.filter((draft) => draft.id !== draftId);

            setSelectedDraftId((selectedId) => {
                if (selectedId !== draftId) {
                    return selectedId;
                }

                return next[0]?.id ?? null;
            });

            return next;
        });
    }, []);

    const updateDraftMetadata = useCallback(
        (draftId: string | null, patch: Partial<TrainingDraftMetadata>) => {
            if (draftId) {
                setDrafts((current) =>
                    current.map((draft) =>
                        draft.id === draftId ? { ...draft, ...patch } : draft,
                    ),
                );
            } else {
                setStandaloneMetadata((current) => ({ ...current, ...patch }));
            }
        },
        [],
    );

    const applyMetadataToAll = useCallback(() => {
        if (!selectedDraft || drafts.length < 2) {
            return;
        }

        const metadata = copyTrainingMetadataFromSource(selectedDraft);

        setDrafts((current) =>
            current.map((draft) =>
                draft.id === selectedDraft.id ? draft : { ...draft, ...metadata },
            ),
        );
    }, [drafts.length, selectedDraft]);

    const uploadFileSize = useMemo(
        () => drafts.reduce((total, draft) => total + draft.file.size, 0),
        [drafts],
    );

    const draftHasVisibleContent = useCallback(
        (metadata: TrainingDraftMetadata, certificate: File | null): boolean =>
            hasVisibleTrainingContent(metadata, {
                templateFields,
                certificate,
                existingCertificate:
                    isEdit && !removeCertificate
                        ? (editingTraining?.certificate_url ?? null)
                        : null,
            }),
        [editingTraining?.certificate_url, isEdit, removeCertificate, templateFields],
    );

    const canSaveCreate =
        !isSaving &&
        (drafts.length > 0
            ? drafts.every(
                  (draft) =>
                      draftMeetsRequired(draft, draft.file) &&
                      draftHasVisibleContent(draft, draft.file),
              )
            : draftMeetsRequired(standaloneMetadata, null) &&
              draftHasVisibleContent(standaloneMetadata, null));

    const canSaveEdit =
        !isSaving &&
        draftMeetsRequired(standaloneMetadata, selectedDraft?.file ?? null) &&
        draftHasVisibleContent(standaloneMetadata, selectedDraft?.file ?? null);

    const submitCreate = useCallback(async () => {
        if (isSaving) {
            return;
        }

        const entries: TrainingDraft[] =
            drafts.length > 0
                ? drafts
                : [
                      {
                          ...standaloneMetadata,
                          id: createUploadDraftId(),
                          file: null as unknown as File,
                      },
                  ];

        for (const entry of entries) {
            const hasFile = entry.file instanceof File;

            if (
                !validateRequired(
                    trainingDraftToFormData(entry, hasFile ? entry.file : null, null),
                )
            ) {
                if (hasFile) {
                    setSelectedDraftId(entry.id);
                }

                return;
            }
        }

        let resolvedEmployeeId: number;

        try {
            resolvedEmployeeId = await resolveEmployeeIdForSave(employeeId, ensureEmployee);
        } catch {
            return;
        }

        setIsSaving(true);
        setFieldErrors({});

        const submitNext = (index: number) => {
            if (index >= entries.length) {
                onOpenChange(false);
                resetDialog();

                return;
            }

            const entry = entries[index];
            const hasFile = entry.file instanceof File;

            router.post(
                storeTraining.url({ employee: resolvedEmployeeId }),
                buildTrainingSubmitPayload(entry, {
                    templateFields,
                    certificate: hasFile ? entry.file : null,
                }),
                {
                    forceFormData: true,
                    ...TRAINING_RELOAD,
                    preserveScroll: true,
                    onSuccess: () => submitNext(index + 1),
                    onError: (errors) => {
                        setFieldErrors(parseTrainingFieldErrors(errors));

                        if (hasFile) {
                            setSelectedDraftId(entry.id);
                        }

                        setIsSaving(false);
                    },
                    onFinish: () => {
                        if (index === entries.length - 1) {
                            setIsSaving(false);
                        }
                    },
                },
            );
        };

        submitNext(0);
    }, [
        drafts,
        employeeId,
        ensureEmployee,
        isSaving,
        onOpenChange,
        resetDialog,
        standaloneMetadata,
        templateFields,
        validateRequired,
    ]);

    const submitEdit = useCallback(async () => {
        if (!editingTraining || isSaving) {
            return;
        }

        if (
            !validateRequired(
                trainingDraftToFormData(
                    standaloneMetadata,
                    selectedDraft?.file ?? null,
                    !removeCertificate && editingTraining.certificate_url ? 'existing' : null,
                ),
            )
        ) {
            return;
        }

        let resolvedEmployeeId: number;

        try {
            resolvedEmployeeId = await resolveEmployeeIdForSave(employeeId, ensureEmployee);
        } catch {
            return;
        }

        setIsSaving(true);
        setFieldErrors({});

        router.put(
            updateTraining.url({
                employee: resolvedEmployeeId,
                training: editingTraining.id,
            }),
            buildTrainingSubmitPayload(standaloneMetadata, {
                templateFields,
                certificate: selectedDraft?.file ?? null,
                removeCertificate,
            }),
            {
                forceFormData: true,
                ...TRAINING_RELOAD,
                onSuccess: () => {
                    onOpenChange(false);
                    resetDialog();
                },
                onError: (errors) => {
                    setFieldErrors(parseTrainingFieldErrors(errors));
                },
                onFinish: () => setIsSaving(false),
            },
        );
    }, [
        editingTraining,
        employeeId,
        ensureEmployee,
        isSaving,
        onOpenChange,
        removeCertificate,
        resetDialog,
        selectedDraft?.file,
        standaloneMetadata,
        templateFields,
        validateRequired,
    ]);

    const courseLabelForDraft = useCallback(
        (draft: TrainingDraft) =>
            courses.find((course) => String(course.id) === draft.course_id)?.name,
        [courses],
    );

    const showRightForm =
        isEdit || drafts.length === 0 || selectedDraft !== null;

    const formPanel = (
        <div className="space-y-4 rounded-2xl border border-border bg-card/40 p-4">
            <AddTrainingDraftForm
                draft={isEdit ? standaloneMetadata : activeMetadata}
                courses={courses}
                countries={countries}
                onChange={(patch) =>
                    updateDraftMetadata(isEdit ? null : selectedDraft?.id ?? null, patch)
                }
                fieldErrors={fieldErrors}
                showApplyToAll={!isEdit && drafts.length > 1}
                onApplyToAll={applyMetadataToAll}
                showField={showField}
                isFieldRequired={isFieldRequired}
                isMissingRequired={isMissingRequired}
                existingCertificateUrl={
                    isEdit && !removeCertificate
                        ? (editingTraining?.certificate_url ?? null)
                        : null
                }
                removeCertificate={removeCertificate}
                onRemoveCertificateChange={
                    isEdit && editingTraining?.certificate_url
                        ? setRemoveCertificate
                        : undefined
                }
                certificateError={fieldErrors.certificate}
            />
        </div>
    );

    const emptySelectionPanel = (
        <div className="flex h-full min-h-[280px] flex-col items-center justify-center gap-3 rounded-2xl border border-border bg-card/40 px-4 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                <FileText className="h-6 w-6" />
            </div>
            <div className="text-sm font-semibold">Select a certificate</div>
            <p className="max-w-xs text-xs text-muted-foreground">
                Choose a certificate from the list to enter its training details.
            </p>
        </div>
    );

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                if (nextOpen) {
                    initializeForOpen();
                }

                onOpenChange(nextOpen);

                if (!nextOpen) {
                    resetDialog();
                }
            }}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit training' : 'Add training'}</DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        {isEdit
                            ? `Update training details for ${employeeName}.`
                            : showCertificatePanel
                              ? `Add training records for ${employeeName}. Select a certificate on the left, then enter its details on the right.`
                              : `Add a training record for ${employeeName}.`}
                    </p>
                </DialogHeader>

                <EmployeeMissingRequiredFieldsAlert
                    missingFields={missingRequiredFieldsList}
                    onFocusField={focusMissingField}
                />

                {showCertificatePanel ? (
                    <div className="grid gap-5 py-2 lg:grid-cols-[1.1fr_0.9fr]">
                        <div className="space-y-4">
                            <div
                                onDragOver={(event) => {
                                    event.preventDefault();
                                    setIsDraggingFiles(true);
                                }}
                                onDragLeave={() => setIsDraggingFiles(false)}
                                onDrop={(event) => {
                                    event.preventDefault();
                                    setIsDraggingFiles(false);
                                    addCertificateFiles(Array.from(event.dataTransfer.files));
                                }}
                                className={`rounded-2xl border border-dashed p-6 transition-colors ${
                                    isDraggingFiles
                                        ? 'border-primary bg-primary/10'
                                        : 'border-border bg-muted/20 hover:bg-muted/30'
                                }`}
                            >
                                <div className="flex flex-col items-center gap-3 text-center">
                                    <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 text-primary">
                                        <UploadCloud className="h-6 w-6" />
                                    </div>
                                    <div>
                                        <div className="text-sm font-semibold">
                                            Drag and drop certificate files here
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            Upload up to {MAX_TRAINING_CERTIFICATE_FILES} files.
                                            Supported formats: PDF, JPG, JPEG, PNG. Max 5 MB each.
                                        </div>
                                    </div>
                                    <label className="inline-flex cursor-pointer items-center rounded-lg bg-primary px-4 py-2 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90">
                                        Browse files
                                        <input
                                            type="file"
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            multiple={!isEdit}
                                            className="sr-only"
                                            onChange={(event) => {
                                                addCertificateFiles(
                                                    Array.from(event.target.files ?? []),
                                                );
                                                event.currentTarget.value = '';
                                            }}
                                        />
                                    </label>
                                </div>
                            </div>

                            <div className="rounded-2xl border border-border bg-card/40">
                                <div className="flex items-center justify-between border-b border-border px-4 py-3">
                                    <div>
                                        <div className="text-sm font-semibold">
                                            Selected certificates
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {drafts.length} file(s),{' '}
                                            {formatUploadFileSize(uploadFileSize)} total
                                        </div>
                                    </div>
                                    {drafts.length > 0 ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                setDrafts([]);
                                                setSelectedDraftId(null);
                                            }}
                                        >
                                            Clear
                                        </Button>
                                    ) : null}
                                </div>
                                <div className="max-h-56 space-y-2 overflow-y-auto p-3">
                                    {drafts.length === 0 ? (
                                        <div className="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                                            {isEdit
                                                ? 'No replacement file selected. The current certificate is kept unless removed.'
                                                : isFieldRequired(CERTIFICATE_TEMPLATE_FIELD)
                                                  ? 'Add a certificate file to continue.'
                                                  : 'No certificate selected yet (optional).'}
                                        </div>
                                    ) : (
                                        drafts.map((draft, index) => (
                                            <AddTrainingCertificateListItem
                                                key={draft.id}
                                                draft={draft}
                                                index={index}
                                                courseLabel={courseLabelForDraft(draft)}
                                                selected={selectedDraftId === draft.id}
                                                hasErrors={Boolean(
                                                    selectedDraftId === draft.id &&
                                                        Object.keys(fieldErrors).length > 0,
                                                )}
                                                onSelect={() => setSelectedDraftId(draft.id)}
                                                onRemove={() => removeDraft(draft.id)}
                                            />
                                        ))
                                    )}
                                </div>
                            </div>
                        </div>

                        {showRightForm ? formPanel : emptySelectionPanel}
                    </div>
                ) : (
                    formPanel
                )}

                <DialogFooter className="items-center border-t border-border/60 pt-4 sm:justify-between">
                    <div className="text-xs text-muted-foreground">
                        {isEdit
                            ? 'Save updates to this training record.'
                            : drafts.length === 0
                              ? 'Enter training details to create a record.'
                              : drafts.length > 1
                                ? 'Each certificate will create its own training record.'
                                : 'One training record will be created.'}
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            className={actions.dialogSecondary}
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            className={actions.dialogPrimary}
                            disabled={isEdit ? !canSaveEdit : !canSaveCreate}
                            onClick={isEdit ? submitEdit : submitCreate}
                        >
                            {isSaving
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save'
                                  : drafts.length > 1
                                    ? `Add ${drafts.length} trainings`
                                    : 'Add training'}
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
