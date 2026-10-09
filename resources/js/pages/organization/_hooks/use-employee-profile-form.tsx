import { useForm } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { Dispatch, ReactElement, SetStateAction } from 'react';
import { update as updateEmployee } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import { useRegisterUnsavedWork } from '@/hooks/use-register-unsaved-work';
import { toast } from '@/lib/toast';
import { isOfficialEmployeeNumberMissing } from '@/pages/organization/_lib/draft-employee-number';
import {
    buildEmployeeProfileFormInitial,
    buildEmployeeProfileUpdatePayload,
    employeeProfileUpdateRequiresPostSpoof,
    isEmployeeProfileFormDirty,
    resolveEmployeeProfileSaveVisit,
} from '@/pages/organization/_lib/employee-profile-form-state';
import { resolveEmployeeProfilePreserveState } from '@/pages/organization/_lib/employee-profile-persisted-state';
import {
    fetchHireDateChangePreview,
    HIRE_DATE_CHANGE_PREVIEW_ERROR_MESSAGE,
    hireDateCalendarValueChanged,
    resolveHireDateChangePreviewFetchResult,
} from '@/pages/organization/_lib/hire-date-change-preview';
import type { HireDateChangePreview } from '@/pages/organization/_lib/hire-date-change-preview';
import type {
    EmployeeDetails,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';

const DEFAULT_REQUIRED_FIELDS = new Set(['employee_no', 'name']);
const LOCKED_REQUIRED_FIELDS = new Set(['employee_no', 'name']);

export type UseEmployeeProfileFormResult = {
    form: any;
    isDirty: boolean;
    displayName: string;
    activeField: string | null;
    setActiveField: Dispatch<SetStateAction<string | null>>;
    beginEdit: (field: string) => void;
    requiredDot: (field: string) => ReactElement | null;
    isMissingRequired: (field: string) => boolean;
    missingRequiredFields: string[];
    focusMissingField: (field: string) => void;
    saveChanges: (
        afterSuccess?: () => void,
        options?: { hireDateChangeAcknowledged?: boolean },
    ) => void;
    requestHireDateWarning: (
        preview: HireDateChangePreview,
        afterSuccess?: () => void,
    ) => void;
    stagePhoto: (file: File) => void;
    removePhoto: () => void;
    discardChanges: () => void;
};

export function useEmployeeProfileForm(
    employee: EmployeeDetails,
    canUpdate: boolean,
    options?: {
        ensureEmployee?: () => Promise<number>;
        templateRequiredFields?:
            | Record<string, TemplateFieldConfig>
            | undefined;
        listQuery?: Record<string, string>;
        hasAnnualLeaveBalances?: boolean;
        savedHireDate?: string | null;
        onHireDateWarningRequired?: (
            preview: HireDateChangePreview,
            continueSave: () => void,
        ) => void;
    },
): UseEmployeeProfileFormResult {
    const [activeField, setActiveField] = useState<string | null>(null);
    const [missingRequiredFields, setMissingRequiredFields] = useState<
        Set<string>
    >(() => new Set());
    const ensureEmployee = options?.ensureEmployee;
    const previousEmployeeIdRef = useRef<number | null>(employee.id);
    const hireDatePreviewInFlightRef = useRef(false);

    const initialPersonal = useMemo(
        () => buildEmployeeProfileFormInitial(employee),
        // Only refresh form baseline when persisted identity changes (not on every draft keystroke).
        // eslint-disable-next-line react-hooks/exhaustive-deps -- employee
        [employee.id, employee.updated_at],
    );

    const form = useForm(initialPersonal);

    // After a successful create redirects to a blank create page, drop the
    // previous provisional employee's form values. Do not reset when ensure
    // first assigns an id (null → positive) — that must keep typed fields.
    useEffect(() => {
        const previousId = previousEmployeeIdRef.current;
        previousEmployeeIdRef.current = employee.id;

        const becameFreshCreate =
            previousId !== null &&
            previousId > 0 &&
            (employee.id === null || employee.id <= 0);

        if (!becameFreshCreate) {
            return;
        }

        form.setData(initialPersonal);
        form.clearErrors();
        setActiveField(null);
        setMissingRequiredFields(new Set());
    }, [employee.id, form, initialPersonal]);

    const isDirty = useMemo(() => {
        if (form.data.image instanceof File) {
            return true;
        }

        if (form.data.remove_image && employee.image) {
            return true;
        }

        return isEmployeeProfileFormDirty(form.data, initialPersonal);
    }, [employee.image, form.data, initialPersonal]);

    const displayName = useMemo(() => {
        return String(form.data.name ?? '').trim() || 'Employee';
    }, [form.data.name]);

    const requiredFields = useMemo(() => {
        const employeeFields = options?.templateRequiredFields;

        if (!employeeFields) {
            return DEFAULT_REQUIRED_FIELDS;
        }

        const keys = new Set<string>();

        for (const [key, config] of Object.entries(employeeFields)) {
            if (config.visible && config.required) {
                keys.add(key);
            }
        }

        for (const locked of LOCKED_REQUIRED_FIELDS) {
            keys.add(locked);
        }

        return keys;
    }, [options?.templateRequiredFields]);

    const requiredDot = useCallback(
        (field: string): ReactElement | null => {
            if (!requiredFields.has(field)) {
                return null;
            }

            return (
                <span className="ml-1 inline-flex h-1.5 w-1.5 rounded-full bg-rose-500/90 align-middle" />
            );
        },
        [requiredFields],
    );

    const activeMissingRequiredFields = useMemo(() => {
        const active = new Set<string>();

        for (const field of missingRequiredFields) {
            const raw = form.data[field as keyof typeof form.data] ?? '';

            if (field === 'employee_no') {
                if (isOfficialEmployeeNumberMissing(raw)) {
                    active.add(field);
                }

                continue;
            }

            if (String(raw).trim() === '') {
                active.add(field);
            }
        }

        return active;
    }, [form, missingRequiredFields]);

    const isMissingRequired = useCallback(
        (field: string) => activeMissingRequiredFields.has(field),
        [activeMissingRequiredFields],
    );

    const missingRequiredFieldsList = useMemo(
        () => Array.from(activeMissingRequiredFields),
        [activeMissingRequiredFields],
    );

    const beginEdit = useCallback(
        (field: string) => {
            if (!canUpdate) {
                return;
            }

            setActiveField(field);
        },
        [canUpdate],
    );

    const focusMissingField = useCallback(
        (field: string) => {
            beginEdit(field);
            requestAnimationFrame(() => {
                document
                    .querySelector(`[data-employee-field="${field}"]`)
                    ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        },
        [beginEdit],
    );

    useEffect(() => {
        if (!canUpdate || !isDirty) {
            return;
        }

        const handler = (e: BeforeUnloadEvent) => {
            e.preventDefault();
        };

        window.addEventListener('beforeunload', handler);

        return () => window.removeEventListener('beforeunload', handler);
    }, [canUpdate, isDirty]);

    useRegisterUnsavedWork(canUpdate && isDirty);

    const submitProfileUpdate = useCallback(
        (
            targetEmployeeId: number,
            afterSuccess?: () => void,
            hireDateChangeAcknowledged = false,
        ) => {
            const hasPendingImage = employeeProfileUpdateRequiresPostSpoof(
                form.data.image,
            );
            const saveVisit = resolveEmployeeProfileSaveVisit(form.data.image);

            const extraFields = hireDateChangeAcknowledged
                ? { hire_date_change_acknowledged: true }
                : undefined;

            form.transform((data) =>
                buildEmployeeProfileUpdatePayload(
                    data,
                    options?.templateRequiredFields,
                    extraFields,
                ),
            );

            const updateUrl = updateEmployee.url(
                { employee: targetEmployeeId },
                { query: options?.listQuery ?? {} },
            );

            const visitOptions = {
                preserveScroll: true,
                preserveState: resolveEmployeeProfilePreserveState(),
                onSuccess: () => {
                    if (hasPendingImage) {
                        form.setData((current) => ({
                            ...current,
                            image: null,
                            remove_image: false,
                        }));
                    }

                    setActiveField(null);
                    setMissingRequiredFields(new Set());
                    afterSuccess?.();
                },
                onError: (errors: Record<string, string>) => {
                    const errorKeys = Object.keys(errors ?? {});

                    if (errorKeys.includes('employee_no')) {
                        setMissingRequiredFields((current) => {
                            const next = new Set(current);
                            next.add('employee_no');

                            return next;
                        });
                        focusMissingField('employee_no');
                    }

                    const first = Object.values(errors ?? {})[0];
                    toast.error(
                        typeof first === 'string' && first.length
                            ? first
                            : 'Failed to save changes.',
                    );
                },
            };

            if (saveVisit.httpMethod === 'post') {
                form.post(updateUrl, {
                    ...visitOptions,
                    forceFormData: saveVisit.forceFormData,
                });

                return;
            }

            form.put(updateUrl, visitOptions);
        },
        [
            focusMissingField,
            form,
            options?.listQuery,
            options?.templateRequiredFields,
        ],
    );

    const requestHireDateWarning = useCallback(
        (preview: HireDateChangePreview, afterSuccess?: () => void) => {
            options?.onHireDateWarningRequired?.(preview, () => {
                const targetId = employee.id;

                if (targetId === null || targetId <= 0) {
                    return;
                }

                submitProfileUpdate(targetId, afterSuccess, true);
            });
        },
        [employee.id, options, submitProfileUpdate],
    );

    const saveChanges = useCallback(
        async (
            afterSuccess?: () => void,
            saveOptions?: { hireDateChangeAcknowledged?: boolean },
        ) => {
            if (canUpdate) {
                const missing: string[] = [];

                for (const field of requiredFields) {
                    if (field === 'image') {
                        if (
                            form.data.remove_image ||
                            (!(form.data.image instanceof File) &&
                                !employee.image)
                        ) {
                            missing.push(field);
                        }

                        continue;
                    }

                    if (field === 'approval_location_ids') {
                        if (
                            (form.data.approval_location_ids ?? []).length === 0
                        ) {
                            missing.push(field);
                        }

                        continue;
                    }

                    if (field === 'sssa_option_ids') {
                        if ((form.data.sssa_option_ids ?? []).length === 0) {
                            missing.push(field);
                        }

                        continue;
                    }

                    if (field === 'employee_no') {
                        if (
                            isOfficialEmployeeNumberMissing(
                                form.data.employee_no,
                            )
                        ) {
                            missing.push(field);
                        }

                        continue;
                    }

                    if (
                        !String(
                            form.data[field as keyof typeof form.data] ?? '',
                        ).trim()
                    ) {
                        missing.push(field);
                    }
                }

                if (missing.length) {
                    setMissingRequiredFields(new Set(missing));
                    toast.error(
                        'Please fill the highlighted required fields before saving.',
                    );
                    focusMissingField(missing[0]);

                    return;
                }
            }

            setMissingRequiredFields(new Set());

            let targetEmployeeId = employee.id;

            if (
                (targetEmployeeId === null || targetEmployeeId <= 0) &&
                ensureEmployee
            ) {
                try {
                    targetEmployeeId = await ensureEmployee();
                } catch {
                    return;
                }
            }

            if (targetEmployeeId === null || targetEmployeeId <= 0) {
                toast.error('Employee name is required before saving.');

                return;
            }

            const proposedHireDate = String(form.data.hire_date ?? '').trim()
                ? String(form.data.hire_date)
                : null;

            if (
                !saveOptions?.hireDateChangeAcknowledged &&
                options?.hasAnnualLeaveBalances &&
                hireDateCalendarValueChanged(
                    options.savedHireDate ?? employee.hire_date,
                    proposedHireDate,
                )
            ) {
                if (hireDatePreviewInFlightRef.current) {
                    return;
                }

                hireDatePreviewInFlightRef.current = true;

                try {
                    const previewResult = await fetchHireDateChangePreview(
                        targetEmployeeId,
                        proposedHireDate,
                    );
                    const nextStep =
                        resolveHireDateChangePreviewFetchResult(previewResult);

                    if (nextStep === 'abort') {
                        toast.error(HIRE_DATE_CHANGE_PREVIEW_ERROR_MESSAGE);

                        return;
                    }

                    if (nextStep === 'show_warning') {
                        if (
                            previewResult.status === 'requires_acknowledgment'
                        ) {
                            requestHireDateWarning(
                                previewResult.preview,
                                afterSuccess,
                            );
                        }

                        return;
                    }
                } finally {
                    hireDatePreviewInFlightRef.current = false;
                }
            }

            submitProfileUpdate(
                targetEmployeeId,
                afterSuccess,
                saveOptions?.hireDateChangeAcknowledged ?? false,
            );
        },
        [
            canUpdate,
            employee.hire_date,
            employee.id,
            employee.image,
            ensureEmployee,
            focusMissingField,
            form,
            options?.hasAnnualLeaveBalances,
            options?.savedHireDate,
            options?.listQuery,
            options?.templateRequiredFields,
            requiredFields,
            requestHireDateWarning,
            submitProfileUpdate,
        ],
    );

    const stagePhoto = useCallback(
        (file: File) => {
            if (!canUpdate) {
                return;
            }

            form.setData((current) => ({
                ...current,
                image: file,
                remove_image: false,
            }));
        },
        [canUpdate, form],
    );

    const removePhoto = useCallback(() => {
        if (!canUpdate) {
            return;
        }

        form.setData((current) => ({
            ...current,
            image: null,
            remove_image: Boolean(employee.image),
        }));
    }, [canUpdate, employee.image, form]);

    const discardChanges = useCallback(() => {
        form.setData(initialPersonal);
        form.clearErrors();
        setActiveField(null);
        setMissingRequiredFields(new Set());
    }, [form, initialPersonal]);

    return {
        form: form as any,
        isDirty,
        displayName,
        activeField,
        setActiveField,
        beginEdit,
        requiredDot,
        isMissingRequired,
        missingRequiredFields: missingRequiredFieldsList,
        focusMissingField,
        saveChanges,
        requestHireDateWarning,
        stagePhoto,
        removePhoto,
        discardChanges,
    };
}
