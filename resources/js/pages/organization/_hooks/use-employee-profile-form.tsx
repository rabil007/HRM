import { useForm } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useMemo,
    useState,
} from 'react';
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
    isUploadingPhoto: boolean;
    displayName: string;
    activeField: string | null;
    setActiveField: Dispatch<SetStateAction<string | null>>;
    beginEdit: (field: string) => void;
    requiredDot: (field: string) => ReactElement | null;
    isMissingRequired: (field: string) => boolean;
    missingRequiredFields: string[];
    focusMissingField: (field: string) => void;
    saveChanges: (afterSuccess?: () => void) => void;
    uploadPhoto: (file: File) => void;
    discardChanges: () => void;
};

export function useEmployeeProfileForm(
    employee: EmployeeDetails,
    canUpdate: boolean,
    options?: {
        ensureEmployee?: () => Promise<number>;
        templateRequiredFields?: Record<string, TemplateFieldConfig> | undefined;
    },
): UseEmployeeProfileFormResult {
    const [activeField, setActiveField] = useState<string | null>(null);
    const [isUploadingPhoto, setIsUploadingPhoto] = useState(false);
    const [missingRequiredFields, setMissingRequiredFields] = useState<Set<string>>(
        () => new Set(),
    );
    const ensureEmployee = options?.ensureEmployee;

    const initialPersonal = useMemo(
        () => buildEmployeeProfileFormInitial(employee),
        // Only refresh form baseline when persisted identity changes (not on every draft keystroke).
        // eslint-disable-next-line react-hooks/exhaustive-deps -- employee
        [employee.id, employee.updated_at],
    );

    const form = useForm(initialPersonal);

    const isDirty = useMemo(
        () => isEmployeeProfileFormDirty(form.data, initialPersonal),
        [form.data, initialPersonal],
    );

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

    const requiredDot = useCallback((field: string): ReactElement | null => {
        if (!requiredFields.has(field)) {
            return null;
        }

        return (
            <span className="ml-1 inline-flex h-1.5 w-1.5 rounded-full bg-rose-500/90 align-middle" />
        );
    }, [requiredFields]);

    const isMissingRequired = useCallback(
        (field: string) => missingRequiredFields.has(field),
        [missingRequiredFields],
    );

    const missingRequiredFieldsList = useMemo(
        () => Array.from(missingRequiredFields),
        [missingRequiredFields],
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
        if (missingRequiredFields.size === 0) {
            return;
        }

        setMissingRequiredFields((current) => {
            const next = new Set(current);
            let changed = false;

            for (const field of current) {
                if (String(form.data[field as keyof typeof form.data] ?? '').trim() !== '') {
                    next.delete(field);
                    changed = true;
                }
            }

            return changed ? next : current;
        });
    // eslint-disable-next-line react-hooks/exhaustive-deps -- clear highlights when field values change
    }, [form.data]);

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
                    if (!String(form.data[field as keyof typeof form.data] ?? '').trim()) {
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

            if ((targetEmployeeId === null || targetEmployeeId <= 0) && ensureEmployee) {
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

            form.transform((data) => transformEmployeeProfileFormData(data));

            form.put(updateEmployee.url({ employee: targetEmployeeId }), {
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
        [canUpdate, employee.id, ensureEmployee, focusMissingField, form, requiredFields],
    );

    const uploadPhoto = useCallback(
        (file: File) => {
            if (!canUpdate) {
                return;
            }

            if (!String(form.data.employee_no ?? '').trim() || !String(form.data.name ?? '').trim()) {
                toast.error('Employee number and name are required before uploading a photo.');

                return;
            }

            setIsUploadingPhoto(true);

            form.transform((data) => ({
                ...transformEmployeeProfileFormData(data),
                image: file,
            }));

            const targetId = employee.id && employee.id > 0 ? employee.id : null;

            if (targetId === null) {
                toast.error('Save employee details before uploading a photo.');

                return;
            }

            form.put(updateEmployee.url({ employee: targetId }), {
                forceFormData: true,
                preserveScroll: true,
                onError: (errors) => {
                    const first = Object.values(errors ?? {})[0];
                    toast.error(
                        typeof first === 'string' && first.length
                            ? first
                            : 'Failed to upload photo.',
                    );
                },
                onFinish: () => {
                    setIsUploadingPhoto(false);
                    form.transform((data) => transformEmployeeProfileFormData(data));
                },
            });
        },
        [canUpdate, employee.id, form],
    );

    const discardChanges = useCallback(() => {
        form.setData(initialPersonal);
        form.clearErrors();
        setActiveField(null);
        setMissingRequiredFields(new Set());
    }, [form, initialPersonal]);

    return {
        form: form as any,
        isDirty,
        isUploadingPhoto,
        displayName,
        activeField,
        setActiveField,
        beginEdit,
        requiredDot,
        isMissingRequired,
        missingRequiredFields: missingRequiredFieldsList,
        focusMissingField,
        saveChanges,
        uploadPhoto,
        discardChanges,
    };
}
