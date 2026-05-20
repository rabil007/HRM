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

function transformEmployeeFormData(data: Record<string, unknown>): Record<string, unknown> {
    return {
        ...data,
        employee_no: String(data.employee_no ?? '').trim() || null,
        name: String(data.name ?? '').trim() || null,
        branch_id: data.branch_id ? Number(data.branch_id) : null,
        department_id: data.department_id ? Number(data.department_id) : null,
        position_id: data.position_id ? Number(data.position_id) : null,
        manager_id: data.manager_id ? Number(data.manager_id) : null,
        personal_email: String(data.personal_email ?? '').trim() || null,
        work_email: String(data.work_email ?? '').trim() || null,
        phone: String(data.phone ?? '').trim() || null,
        phone_home_country: String(data.phone_home_country ?? '').trim() || null,
        emergency_contact: String(data.emergency_contact ?? '').trim() || null,
        emergency_phone: String(data.emergency_phone ?? '').trim() || null,
        nearest_airport: String(data.nearest_airport ?? '').trim() || null,
        address: String(data.address ?? '').trim() || null,
        date_of_birth: data.date_of_birth || null,
        place_of_birth: String(data.place_of_birth ?? '').trim() || null,
        gender_id: data.gender_id ? Number(data.gender_id) : null,
        religion_id: data.religion_id ? Number(data.religion_id) : null,
        nationality_id: data.nationality_id ? Number(data.nationality_id) : null,
        marital_status: data.marital_status || null,
        spouse_name: String(data.spouse_name ?? '').trim() || null,
        spouse_birthdate: data.spouse_birthdate || null,
        passport_number: String(data.passport_number ?? '').trim() || null,
        emirates_id: String(data.emirates_id ?? '').trim() || null,
        labor_card_number: String(data.labor_card_number ?? '').trim() || null,
    };
}

export function useEmployeeProfileForm(
    employee: EmployeeDetails,
    canUpdate: boolean,
): UseEmployeeProfileFormResult {
    const [activeField, setActiveField] = useState<string | null>(null);
    const [isUploadingPhoto, setIsUploadingPhoto] = useState(false);

    const initialPersonal = useMemo(
        () => ({
            employee_no: employee.employee_no ?? '',
            name: employee.name ?? '',
            branch_id: employee.branch?.id ? String(employee.branch.id) : '',
            department_id: employee.department?.id
                ? String(employee.department.id)
                : '',
            position_id: employee.position?.id
                ? String(employee.position.id)
                : '',
            rank_id: employee.rank_id ? String(employee.rank_id) : '',
            manager_id: employee.manager?.id ? String(employee.manager.id) : '',
            personal_email:
                employee.personal_email ?? employee.work_email ?? '',
            work_email: employee.work_email ?? '',
            phone: employee.phone ?? '',
            phone_home_country: employee.phone_home_country ?? '',
            emergency_contact: employee.emergency_contact ?? '',
            emergency_phone: employee.emergency_phone ?? '',
            nearest_airport: employee.nearest_airport ?? '',
            address: employee.address ?? '',
            date_of_birth: employee.date_of_birth ?? '',
            place_of_birth: employee.place_of_birth ?? '',
            gender_id: employee.gender_id ? String(employee.gender_id) : '',
            religion_id: employee.religion_id
                ? String(employee.religion_id)
                : '',
            nationality_id: employee.nationality_id
                ? String(employee.nationality_id)
                : '',
            marital_status: employee.marital_status ?? '',
            spouse_name: employee.spouse_name ?? '',
            spouse_birthdate: employee.spouse_birthdate ?? '',
            passport_number: employee.passport_number ?? '',
            emirates_id: employee.emirates_id ?? '',
            labor_card_number: employee.labor_card_number ?? '',
        }),
        [
            employee.employee_no,
            employee.name,
            employee.branch,
            employee.department,
            employee.position,
            employee.rank_id,
            employee.manager,
            employee.personal_email,
            employee.work_email,
            employee.phone,
            employee.phone_home_country,
            employee.emergency_contact,
            employee.emergency_phone,
            employee.nearest_airport,
            employee.address,
            employee.date_of_birth,
            employee.place_of_birth,
            employee.gender_id,
            employee.religion_id,
            employee.nationality_id,
            employee.marital_status,
            employee.spouse_name,
            employee.spouse_birthdate,
            employee.passport_number,
            employee.emirates_id,
            employee.labor_card_number,
        ],
    );

    const form = useForm(initialPersonal);

    const isDirty = useMemo(() => {
        return (Object.keys(initialPersonal) as Array<keyof typeof initialPersonal>).some(
            (key) =>
                String(form.data[key] ?? '') !== String(initialPersonal[key] ?? ''),
        );
    }, [form.data, initialPersonal]);

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

            form.transform((data) => transformEmployeeFormData(data));

            form.put(updateEmployee.url({ employee: employee.id }), {
                preserveScroll: true,
                onSuccess: () => {
                    setActiveField(null);
                    toast.success('Changes saved.');
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
                ...transformEmployeeFormData(data),
                image: file,
            }));

            form.put(updateEmployee.url({ employee: employee.id }), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Photo updated.');
                },
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
                    form.transform((data) => transformEmployeeFormData(data));
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
