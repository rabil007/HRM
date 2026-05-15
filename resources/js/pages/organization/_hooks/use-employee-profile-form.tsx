import { useForm } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useMemo,
    useState
    
    
    
} from 'react';
import type {Dispatch, ReactElement, SetStateAction} from 'react';
import { update as updateEmployee } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import { toast } from '@/lib/toast';
import type {
    EmployeeContractDetails,
    EmployeeDetails,
} from '@/pages/organization/employee-page.types';

const REQUIRED_FIELDS = new Set([
    'employee_no',
    'name',
    'start_date',
    'contract_type',
]);

export type UseEmployeeProfileFormResult = {
    form: any;
    isDirty: boolean;
    displayName: string;
    activeField: string | null;
    setActiveField: Dispatch<SetStateAction<string | null>>;
    beginEdit: (field: string) => void;
    requiredDot: (field: string) => ReactElement | null;
    saveChanges: (afterSuccess?: () => void) => void;
    discardChanges: () => void;
};

export function useEmployeeProfileForm(
    employee: EmployeeDetails,
    contract: EmployeeContractDetails | null,
    canUpdate: boolean,
): UseEmployeeProfileFormResult {
    const [activeField, setActiveField] = useState<string | null>(null);

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
            cv_source: employee.cv_source ?? '',
            emergency_contact: employee.emergency_contact ?? '',
            emergency_phone: employee.emergency_phone ?? '',
            emergency_contact_home_country:
                employee.emergency_contact_home_country ?? '',
            emergency_phone_home_country:
                employee.emergency_phone_home_country ?? '',
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
            dependent_children_count:
                employee.dependent_children_count === null ||
                employee.dependent_children_count === undefined
                    ? ''
                    : String(employee.dependent_children_count),
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
            employee.cv_source,
            employee.emergency_contact,
            employee.emergency_phone,
            employee.emergency_contact_home_country,
            employee.emergency_phone_home_country,
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
            employee.dependent_children_count,
            employee.passport_number,
            employee.emirates_id,
            employee.labor_card_number,
        ],
    );

    const initialContract = useMemo(
        () => ({
            contract_type:
                contract?.contract_type ??
                employee.contract_type ??
                'unlimited',
            start_date: contract?.start_date ?? employee.start_date ?? '',
            end_date: contract?.end_date ?? employee.end_date ?? '',
            probation_end_date:
                contract?.probation_end_date ??
                employee.probation_end_date ??
                '',
            labor_contract_id:
                contract?.labor_contract_id ?? employee.labor_contract_id ?? '',
            basic_salary:
                contract?.basic_salary === null ||
                contract?.basic_salary === undefined
                    ? ''
                    : String(contract.basic_salary),
            housing_allowance:
                contract?.housing_allowance === null ||
                contract?.housing_allowance === undefined
                    ? ''
                    : String(contract.housing_allowance),
            transport_allowance:
                contract?.transport_allowance === null ||
                contract?.transport_allowance === undefined
                    ? ''
                    : String(contract.transport_allowance),
            other_allowances:
                contract?.other_allowances === null ||
                contract?.other_allowances === undefined
                    ? ''
                    : String(contract.other_allowances),
        }),
        [
            contract,
            employee.contract_type,
            employee.start_date,
            employee.end_date,
            employee.probation_end_date,
            employee.labor_contract_id,
        ],
    );

    const initialBank = useMemo(
        () => ({
            bank_id: employee.bank_id ? String(employee.bank_id) : '',
            account_name: employee.account_name ?? '',
            iban: employee.iban ?? '',
        }),
        [employee.account_name, employee.bank_id, employee.iban],
    );

    const initialAll = useMemo(
        () => ({ ...initialPersonal, ...initialContract, ...initialBank }),
        [initialBank, initialContract, initialPersonal],
    );

    const form = useForm(initialAll);

    const isDirty = useMemo(() => {
        return (Object.keys(initialAll) as Array<keyof typeof initialAll>).some(
            (key) =>
                String(form.data[key] ?? '') !== String(initialAll[key] ?? ''),
        );
    }, [form.data, initialAll]);

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

                if (!String(form.data.start_date ?? '').trim()) {
                    missing.push('start_date');
                }

                if (!String(form.data.contract_type ?? '').trim()) {
                    missing.push('contract_type');
                }

                if (missing.length) {
                    toast.error(
                        'Please fill the required fields before saving.',
                    );
                    beginEdit(missing[0]);

                    return;
                }
            }

            form.transform((data) => ({
                ...data,
                employee_no: data.employee_no?.trim() || null,
                name: data.name?.trim() || null,
                branch_id: data.branch_id ? Number(data.branch_id) : null,
                department_id: data.department_id
                    ? Number(data.department_id)
                    : null,
                position_id: data.position_id ? Number(data.position_id) : null,
                manager_id: data.manager_id ? Number(data.manager_id) : null,
                personal_email: data.personal_email?.trim() || null,
                work_email: data.work_email?.trim() || null,
                phone: data.phone?.trim() || null,
                phone_home_country: data.phone_home_country?.trim() || null,
                cv_source: data.cv_source?.trim() || null,
                emergency_contact: data.emergency_contact?.trim() || null,
                emergency_phone: data.emergency_phone?.trim() || null,
                emergency_contact_home_country:
                    data.emergency_contact_home_country?.trim() || null,
                emergency_phone_home_country:
                    data.emergency_phone_home_country?.trim() || null,
                nearest_airport: data.nearest_airport?.trim() || null,
                address: data.address?.trim() || null,
                date_of_birth: data.date_of_birth || null,
                place_of_birth: data.place_of_birth?.trim() || null,
                gender_id: data.gender_id ? Number(data.gender_id) : null,
                religion_id: data.religion_id ? Number(data.religion_id) : null,
                nationality_id: data.nationality_id
                    ? Number(data.nationality_id)
                    : null,
                marital_status: data.marital_status || null,
                spouse_name: data.spouse_name?.trim() || null,
                spouse_birthdate: data.spouse_birthdate || null,
                dependent_children_count:
                    data.dependent_children_count === ''
                        ? null
                        : Number(data.dependent_children_count),
                contract_type: data.contract_type,
                start_date: data.start_date,
                end_date: data.end_date || null,
                probation_end_date: data.probation_end_date || null,
                labor_contract_id: data.labor_contract_id?.trim() || null,
                passport_number: data.passport_number?.trim() || null,
                emirates_id: data.emirates_id?.trim() || null,
                labor_card_number: data.labor_card_number?.trim() || null,
                basic_salary:
                    data.basic_salary === '' ? null : Number(data.basic_salary),
                housing_allowance:
                    data.housing_allowance === ''
                        ? null
                        : Number(data.housing_allowance),
                transport_allowance:
                    data.transport_allowance === ''
                        ? null
                        : Number(data.transport_allowance),
                other_allowances:
                    data.other_allowances === ''
                        ? null
                        : Number(data.other_allowances),
                bank_id: data.bank_id ? Number(data.bank_id) : null,
                iban: data.iban?.trim() || null,
                account_name: data.account_name?.trim() || null,
            }));

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

    const discardChanges = useCallback(() => {
        form.setData(initialAll);
        form.clearErrors();
        setActiveField(null);
    }, [form, initialAll]);

    return {
        form: form as any,
        isDirty,
        displayName,
        activeField,
        setActiveField,
        beginEdit,
        requiredDot,
        saveChanges,
        discardChanges,
    };
}
