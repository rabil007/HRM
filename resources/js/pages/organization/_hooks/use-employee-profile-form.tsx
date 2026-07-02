import { useForm } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import type { Dispatch, ReactElement, SetStateAction } from 'react';
import { update as updateEmployee } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import { toast } from '@/lib/toast';
import {
    buildEmployeeProfileFormInitial,
    isEmployeeProfileFormDirty,
    transformEmployeeProfileFormData,
} from '@/pages/organization/_lib/employee-profile-form-state';
import type {
    EmployeeDetails,
    TemplateFieldConfig,
} from '@/pages/organization/employee-page.types';

const DEFAULT_REQUIRED_FIELDS = new Set(['employee_no', 'name']);

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
    saveChanges: (afterSuccess?: () => void) => void;
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
    },
): UseEmployeeProfileFormResult {
    const [activeField, setActiveField] = useState<string | null>(null);
    const [missingRequiredFields, setMissingRequiredFields] = useState<
        Set<string>
    >(() => new Set());
    const ensureEmployee = options?.ensureEmployee;

    const initialPersonal = useMemo(
        () => buildEmployeeProfileFormInitial(employee),
        // Only refresh form baseline when persisted identity changes (not on every draft keystroke).
        // eslint-disable-next-line react-hooks/exhaustive-deps -- employee
        [employee.id, employee.updated_at],
    );

    const form = useForm(initialPersonal);

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

        keys.add('name');

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
            if (
                String(
                    form.data[field as keyof typeof form.data] ?? '',
                ).trim() === ''
            ) {
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

    const saveChanges = useCallback(
        async (afterSuccess?: () => void) => {
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

            const hasPendingImage = form.data.image instanceof File;

            form.transform((data) => {
                const payload = transformEmployeeProfileFormData(
                    data,
                    options?.templateRequiredFields,
                );

                if (data.image instanceof File) {
                    payload.image = data.image;
                }

                if (data.remove_image) {
                    payload.remove_image = true;
                }

                return payload;
            });

            form.put(updateEmployee.url({ employee: targetEmployeeId }), {
                forceFormData: hasPendingImage,
                preserveScroll: true,
                onSuccess: () => {
                    setActiveField(null);
                    setMissingRequiredFields(new Set());
                    afterSuccess?.();
                },
                onError: (errors) => {
                    const first = Object.values(errors ?? {})[0];
                    toast.error(
                        typeof first === 'string' && first.length
                            ? first
                            : 'Failed to save changes.',
                    );
                },
            });
        },
        [
            canUpdate,
            employee.id,
            employee.image,
            ensureEmployee,
            focusMissingField,
            form,
            options?.templateRequiredFields,
            requiredFields,
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
        stagePhoto,
        removePhoto,
        discardChanges,
    };
}
