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
import type { EmployeeDetails } from '@/pages/organization/employee-page.types';

const REQUIRED_FIELDS = new Set(['employee_no', 'name']);

export type UseEmployeeProfileFormResult = {
    form: any;
    isDirty: boolean;
    isUploadingPhoto: boolean;
    displayName: string;
    activeField: string | null;
    setActiveField: Dispatch<SetStateAction<string | null>>;
    beginEdit: (field: string) => void;
    requiredDot: (field: string) => ReactElement | null;
    saveChanges: (afterSuccess?: () => void) => void;
    uploadPhoto: (file: File) => void;
    discardChanges: () => void;
};

export function useEmployeeProfileForm(
    employee: EmployeeDetails,
    canUpdate: boolean,
): UseEmployeeProfileFormResult {
    const [activeField, setActiveField] = useState<string | null>(null);
    const [isUploadingPhoto, setIsUploadingPhoto] = useState(false);

    const initialPersonal = useMemo(
        () => buildEmployeeProfileFormInitial(employee),
        [employee],
    );

    const form = useForm(initialPersonal);

    const isDirty = useMemo(
        () => isEmployeeProfileFormDirty(form.data, initialPersonal),
        [form.data, initialPersonal],
    );

    const displayName = useMemo(() => {
        return String(form.data.name ?? '').trim() || 'Employee';
    }, [form.data.name]);

    const requiredDot = useCallback((field: string): ReactElement | null => {
        if (!REQUIRED_FIELDS.has(field)) {
            return null;
        }

        return (
            <span className="ml-1 inline-flex h-1.5 w-1.5 rounded-full bg-rose-500/90 align-middle" />
        );
    }, []);

    const beginEdit = useCallback(
        (field: string) => {
            if (!canUpdate) {
                return;
            }

            setActiveField(field);
        },
        [canUpdate],
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
        (afterSuccess?: () => void) => {
            if (canUpdate) {
                const missing: string[] = [];

                if (!String(form.data.employee_no ?? '').trim()) {
                    missing.push('employee_no');
                }

                if (!String(form.data.name ?? '').trim()) {
                    missing.push('name');
                }

                if (missing.length) {
                    toast.error(
                        'Please fill the required fields before saving.',
                    );
                    beginEdit(missing[0]);

                    return;
                }
            }

            form.transform((data) => transformEmployeeProfileFormData(data));

            form.put(updateEmployee.url({ employee: employee.id }), {
                preserveScroll: true,
                onSuccess: () => {
                    setActiveField(null);
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
        [beginEdit, canUpdate, employee.id, form],
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

            form.put(updateEmployee.url({ employee: employee.id }), {
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
        saveChanges,
        uploadPhoto,
        discardChanges,
    };
}
