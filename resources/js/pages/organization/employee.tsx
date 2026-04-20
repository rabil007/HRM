import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { Main } from '@/components/layout/main';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type {
    BankOption,
    BranchOption,
    CountryOption,
    DepartmentOption,
    GenderOption,
    ManagerOption,
    PositionOption,
    ReligionOption,
    UserOption,
} from '@/features/organization/employees/types';
import { toast } from '@/lib/toast';
import { EmployeeHeaderCard } from '@/pages/organization/_components/employee-header-card';

type EmployeeDetails = {
    id: number;
    user: { id: number; name: string | null; email: string | null } | null;
    branch: { id: number; name: string | null } | null;
    department: { id: number; name: string | null } | null;
    position: { id: number; title: string | null } | null;
    manager: {
        id: number;
        employee_no: string | null;
        name: string | null;
    } | null;
    employee_no: string;
    first_name: string;
    last_name: string;
    personal_email?: string | null;
    phone_home_country?: string | null;
    nearest_airport?: string | null;
    cv_source?: string | null;
    emergency_contact?: string | null;
    emergency_phone?: string | null;
    emergency_contact_home_country?: string | null;
    emergency_phone_home_country?: string | null;
    date_of_birth?: string | null;
    place_of_birth?: string | null;
    gender_id?: number | null;
    religion_id?: number | null;
    nationality_id?: number | null;
    nationality_ref?: {
        id: number;
        name: string | null;
        code?: string | null;
    } | null;
    marital_status?: 'single' | 'married' | 'divorced' | 'widowed' | null;
    spouse_name?: string | null;
    spouse_birthdate?: string | null;
    dependent_children_count?: number | null;
    labor_contract_id?: string | null;
    passport_number?: string | null;
    emirates_id?: string | null;
    labor_card_number?: string | null;
    bank_id?: number | null;
    iban?: string | null;
    basic_salary?: number | null;
    housing_allowance?: number | null;
    transport_allowance?: number | null;
    other_allowances?: number | null;
    work_email: string | null;
    phone: string | null;
    start_date?: string | null;
    probation_end_date?: string | null;
    end_date?: string | null;
    contract_type: 'limited' | 'unlimited' | 'part_time' | 'contract';
    status: 'active' | 'inactive' | 'on_leave' | 'terminated';
    address?: string | null;
    image?: string | null;
    created_at: string;
    updated_at: string;
};

type ActivityItem = {
    id: number;
    event: string | null;
    description: string;
    causer: { id: number; name: string; email: string } | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string;
};

type EmployeeContractDetails = {
    id: number;
    contract_type: string | null;
    start_date: string | null;
    end_date: string | null;
    probation_end_date: string | null;
    labor_contract_id: string | null;
    status: string | null;
    basic_salary: number | null;
    housing_allowance: number | null;
    transport_allowance: number | null;
    other_allowances: number | null;
    created_at: string;
    updated_at: string;
};

type EmployeeDocumentItem = {
    id: number;
    title: string | null;
    type: string | null;
    document_type: string | null;
    file_path: string;
    issue_date: string | null;
    expiry_date: string | null;
    document_number: string | null;
    notes: string | null;
    status: string | null;
    uploaded_by: number | null;
    created_at: string;
};

export default function EmployeeDetails({
    employee,
    contract,
    documents,
    branches,
    departments,
    positions,
    managers,
    users,
    countries,
    religions,
    genders,
    banks,
    recent_activity,
}: {
    employee: EmployeeDetails;
    contract: EmployeeContractDetails | null;
    documents: EmployeeDocumentItem[];
    branches: BranchOption[];
    departments: DepartmentOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
    countries: CountryOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
    banks: BankOption[];
    recent_activity: ActivityItem[];
}) {
    const { auth } = usePage().props as unknown as {
        auth?: { permissions?: string[] };
    };

    const canUpdate = (auth?.permissions ?? []).includes('employees.update');

    // Avoid unused variable warnings
    void branches;
    void departments;
    void positions;
    void managers;
    void users;
    void banks;
    void recent_activity;

    const [activeField, setActiveField] = useState<string | null>(null);
    const [tabValue, setTabValue] = useState<'personal' | 'contract' | 'documents'>('personal');
    const [pendingTab, setPendingTab] = useState<'personal' | 'contract' | 'documents' | null>(null);
    const [unsavedDialogOpen, setUnsavedDialogOpen] = useState(false);

    const initialPersonal = useMemo(
        () => ({
            employee_no: employee.employee_no ?? '',
            first_name: employee.first_name ?? '',
            last_name: employee.last_name ?? '',
            branch_id: employee.branch?.id ? String(employee.branch.id) : '',
            department_id: employee.department?.id ? String(employee.department.id) : '',
            position_id: employee.position?.id ? String(employee.position.id) : '',
            manager_id: employee.manager?.id ? String(employee.manager.id) : '',
            personal_email: employee.personal_email ?? employee.work_email ?? '',
            work_email: employee.work_email ?? '',
            phone: employee.phone ?? '',
            phone_home_country: employee.phone_home_country ?? '',
            cv_source: employee.cv_source ?? '',
            emergency_contact: employee.emergency_contact ?? '',
            emergency_phone: employee.emergency_phone ?? '',
            emergency_contact_home_country: employee.emergency_contact_home_country ?? '',
            emergency_phone_home_country: employee.emergency_phone_home_country ?? '',
            nearest_airport: employee.nearest_airport ?? '',
            address: employee.address ?? '',
            date_of_birth: employee.date_of_birth ?? '',
            place_of_birth: employee.place_of_birth ?? '',
            gender_id: employee.gender_id ? String(employee.gender_id) : '',
            religion_id: employee.religion_id ? String(employee.religion_id) : '',
            nationality_id: employee.nationality_id ? String(employee.nationality_id) : '',
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
            employee.first_name,
            employee.last_name,
            employee.branch?.id,
            employee.department?.id,
            employee.position?.id,
            employee.manager?.id,
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
            contract_type: contract?.contract_type ?? employee.contract_type ?? 'unlimited',
            start_date: contract?.start_date ?? employee.start_date ?? '',
            end_date: contract?.end_date ?? employee.end_date ?? '',
            probation_end_date: contract?.probation_end_date ?? employee.probation_end_date ?? '',
            labor_contract_id: contract?.labor_contract_id ?? employee.labor_contract_id ?? '',
            basic_salary: contract?.basic_salary === null || contract?.basic_salary === undefined ? '' : String(contract.basic_salary),
            housing_allowance:
                contract?.housing_allowance === null || contract?.housing_allowance === undefined
                    ? ''
                    : String(contract.housing_allowance),
            transport_allowance:
                contract?.transport_allowance === null || contract?.transport_allowance === undefined
                    ? ''
                    : String(contract.transport_allowance),
            other_allowances:
                contract?.other_allowances === null || contract?.other_allowances === undefined
                    ? ''
                    : String(contract.other_allowances),
        }),
        [
            contract?.contract_type,
            contract?.start_date,
            contract?.end_date,
            contract?.probation_end_date,
            contract?.labor_contract_id,
            contract?.basic_salary,
            contract?.housing_allowance,
            contract?.transport_allowance,
            contract?.other_allowances,
            employee.contract_type,
            employee.start_date,
            employee.end_date,
            employee.probation_end_date,
            employee.labor_contract_id,
        ],
    );

    const initialAll = useMemo(() => ({ ...initialPersonal, ...initialContract }), [initialContract, initialPersonal]);

    const form = useForm(initialAll);

    const isDirty = useMemo(() => {
        return (Object.keys(initialAll) as Array<keyof typeof initialAll>).some((key) => {
            return String(form.data[key] ?? '') !== String(initialAll[key] ?? '');
        });
    }, [form.data, initialAll]);

    const requiredFields = useMemo(() => {
        return new Set(['employee_no', 'first_name', 'last_name', 'start_date', 'contract_type']);
    }, []);

    const requiredDot = (field: string) => {
        if (!requiredFields.has(field)) {
            return null;
        }

        return (
            <span className="ml-1 inline-flex h-1.5 w-1.5 rounded-full bg-rose-500/90 align-middle" />
        );
    };

    const beginEdit = (field: string) => {
        if (!canUpdate) {
            return;
        }

        setActiveField(field);
    };

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

    const displayName = useMemo(() => {
        return (
            `${form.data.first_name ?? ''} ${form.data.last_name ?? ''}`.trim() ||
            'Employee'
        );
    }, [form.data.first_name, form.data.last_name]);

    const tabs = [
        { id: 'personal', label: 'Personal' },
        { id: 'contract', label: 'Contract' },
        { id: 'documents', label: 'Documents' },
    ];

    const saveChanges = (afterSuccess?: () => void) => {
        if (canUpdate) {
            const missing: string[] = [];

            if (!String(form.data.employee_no ?? '').trim()) {
                missing.push('employee_no');
            }

            if (!String(form.data.first_name ?? '').trim()) {
                missing.push('first_name');
            }

            if (!String(form.data.last_name ?? '').trim()) {
                missing.push('last_name');
            }

            if (!String(form.data.start_date ?? '').trim()) {
                missing.push('start_date');
            }

            if (!String(form.data.contract_type ?? '').trim()) {
                missing.push('contract_type');
            }

            if (missing.length) {
                toast.error('Please fill the required fields before saving.');
                beginEdit(missing[0]);

                return;
            }
        }

        form.transform((data) => ({
            ...data,
            employee_no: data.employee_no?.trim() || null,
            first_name: data.first_name?.trim() || null,
            last_name: data.last_name?.trim() || null,
            branch_id: data.branch_id ? Number(data.branch_id) : null,
            department_id: data.department_id ? Number(data.department_id) : null,
            position_id: data.position_id ? Number(data.position_id) : null,
            manager_id: data.manager_id ? Number(data.manager_id) : null,
            personal_email: data.personal_email?.trim() || null,
            work_email: data.work_email?.trim() || null,
            phone: data.phone?.trim() || null,
            phone_home_country: data.phone_home_country?.trim() || null,
            cv_source: data.cv_source?.trim() || null,
            emergency_contact: data.emergency_contact?.trim() || null,
            emergency_phone: data.emergency_phone?.trim() || null,
            emergency_contact_home_country: data.emergency_contact_home_country?.trim() || null,
            emergency_phone_home_country: data.emergency_phone_home_country?.trim() || null,
            nearest_airport: data.nearest_airport?.trim() || null,
            address: data.address?.trim() || null,
            date_of_birth: data.date_of_birth || null,
            place_of_birth: data.place_of_birth?.trim() || null,
            gender_id: data.gender_id ? Number(data.gender_id) : null,
            religion_id: data.religion_id ? Number(data.religion_id) : null,
            nationality_id: data.nationality_id ? Number(data.nationality_id) : null,
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
            basic_salary: data.basic_salary === '' ? null : Number(data.basic_salary),
            housing_allowance: data.housing_allowance === '' ? null : Number(data.housing_allowance),
            transport_allowance: data.transport_allowance === '' ? null : Number(data.transport_allowance),
            other_allowances: data.other_allowances === '' ? null : Number(data.other_allowances),
        }));

        form.put(`/organization/employees/${employee.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setActiveField(null);
                toast.success('Changes saved.');
                afterSuccess?.();
            },
            onError: (errors) => {
                const first = Object.values(errors ?? {})[0];
                toast.error(typeof first === 'string' && first.length ? first : 'Failed to save changes.');
            },
        });
    };

    const discardChanges = () => {
        form.setData(initialAll);
        form.clearErrors();
        setActiveField(null);
    };

    const handleTabChange = (next: 'personal' | 'contract' | 'documents') => {
        if (!canUpdate || !isDirty) {
            setTabValue(next);

            return;
        }

        setPendingTab(next);
        setUnsavedDialogOpen(true);
    };

    return (
        <>
            <Head title={`Employee • ${displayName}`} />
            <Main className="bg-background p-0">
                {/* Main Content Area - Full Width */}
                <div className="w-full p-6 md:p-8">
                    <div className="w-full space-y-8">
                        {canUpdate && isDirty ? (
                            <div className="sticky top-4 z-20 rounded-xl border border-white/10 bg-background/80 p-3 backdrop-blur">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="text-sm font-medium text-zinc-200">
                                        You have unsaved changes
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            className="h-9 rounded-lg"
                                            onClick={discardChanges}
                                            disabled={form.processing}
                                        >
                                            Discard
                                        </Button>
                                        <Button
                                            type="button"
                                            className="h-9 rounded-lg"
                                            onClick={() => saveChanges()}
                                            disabled={form.processing}
                                        >
                                            Save
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        ) : null}
                        <AlertDialog open={unsavedDialogOpen} onOpenChange={setUnsavedDialogOpen}>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Unsaved changes</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        You have unsaved changes. What would you like to do?
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel
                                        onClick={() => {
                                            setPendingTab(null);
                                            setUnsavedDialogOpen(false);
                                        }}
                                    >
                                        Stay
                                    </AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={() => {
                                            discardChanges();

                                            if (pendingTab) {
                                                setTabValue(pendingTab);
                                            }

                                            setPendingTab(null);
                                            setUnsavedDialogOpen(false);
                                        }}
                                    >
                                        Discard
                                    </AlertDialogAction>
                                    <AlertDialogAction
                                        onClick={() => {
                                            saveChanges(() => {
                                                if (pendingTab) {
                                                    setTabValue(pendingTab);
                                                }

                                                setPendingTab(null);
                                                setUnsavedDialogOpen(false);
                                            });
                                        }}
                                    >
                                        Save
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>

                        <EmployeeHeaderCard
                            canUpdate={canUpdate}
                            employee={employee}
                            branches={branches}
                            departments={departments}
                            positions={positions}
                            managers={managers}
                            genders={genders}
                            religions={religions}
                            form={form}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            requiredDot={requiredDot}
                        />

                    {/* Tabs Navigation */}
                        <div>
                            <Tabs value={tabValue} onValueChange={(v) => handleTabChange(v as any)} className="w-full">
                            <TabsList className="hide-scrollbar h-auto w-full flex-nowrap justify-start gap-1 overflow-x-auto rounded-xl border border-white/5 bg-white/5 p-1">
                                {tabs.map((tab) => (
                                    <TabsTrigger
                                        key={tab.id}
                                        value={tab.id}
                                        className="shrink-0 rounded-lg border border-transparent bg-transparent px-3 py-2 text-xs font-semibold tracking-wide whitespace-nowrap text-zinc-400 hover:text-zinc-200 data-[state=active]:border-white/10 data-[state=active]:bg-background data-[state=active]:text-white"
                                    >
                                        {tab.label}
                                    </TabsTrigger>
                                ))}
                            </TabsList>

                            <TabsContent
                                value="personal"
                                className="mt-6"
                            >
                                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                                    <div className="space-y-6">
                                        <div className="rounded-xl border border-white/5 bg-white/5 p-5">
                                            <div className="mb-4 flex items-center justify-between gap-3">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Private contact
                                                </h3>
                                            </div>

                                            <div className="space-y-4">
                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">Email</label>
                                                    {activeField === 'personal_email' ? (
                                                        <div>
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.personal_email}
                                                                onChange={(e) => form.setData('personal_email', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.personal_email ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.personal_email}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('personal_email')}
                                                        >
                                                            {form.data.personal_email || employee.personal_email || '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">Phone (Home Country)</label>
                                                    {activeField === 'phone_home_country' ? (
                                                        <div>
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.phone_home_country}
                                                                onChange={(e) => form.setData('phone_home_country', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.phone_home_country ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.phone_home_country}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('phone_home_country')}
                                                        >
                                                            {form.data.phone_home_country || employee.phone_home_country || '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">Source Of CV</label>
                                                    {activeField === 'cv_source' ? (
                                                        <div>
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.cv_source}
                                                                onChange={(e) => form.setData('cv_source', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.cv_source ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.cv_source}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('cv_source')}
                                                        >
                                                            {form.data.cv_source || employee.cv_source || '—'}
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="rounded-xl border border-white/5 bg-white/5 p-5">
                                        <div className="mb-4 flex items-center justify-between">
                                            <h3 className="text-sm font-semibold text-zinc-200">
                                                Emergency contact
                                            </h3>
                                        </div>

                                        <div className="space-y-3">
                                            {[
                                                {
                                                    label: 'Contact',
                                                    value:
                                                        activeField === 'emergency_contact' ? (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.emergency_contact}
                                                                onChange={(e) => form.setData('emergency_contact', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit('emergency_contact')}
                                                            >
                                                                {form.data.emergency_contact || employee.emergency_contact || '—'}
                                                            </button>
                                                        ),
                                                },
                                                {
                                                    label: 'Phone',
                                                    value:
                                                        activeField === 'emergency_phone' ? (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.emergency_phone}
                                                                onChange={(e) => form.setData('emergency_phone', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit('emergency_phone')}
                                                            >
                                                                {form.data.emergency_phone || employee.emergency_phone || '—'}
                                                            </button>
                                                        ),
                                                },
                                                {
                                                    label: 'Home country contact',
                                                    value:
                                                        activeField === 'emergency_contact_home_country' ? (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.emergency_contact_home_country}
                                                                onChange={(e) => form.setData('emergency_contact_home_country', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit('emergency_contact_home_country')}
                                                            >
                                                                {form.data.emergency_contact_home_country ||
                                                                    employee.emergency_contact_home_country ||
                                                                    '—'}
                                                            </button>
                                                        ),
                                                },
                                                {
                                                    label: 'Home country phone',
                                                    value:
                                                        activeField === 'emergency_phone_home_country' ? (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.emergency_phone_home_country}
                                                                onChange={(e) => form.setData('emergency_phone_home_country', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit('emergency_phone_home_country')}
                                                            >
                                                                {form.data.emergency_phone_home_country ||
                                                                    employee.emergency_phone_home_country ||
                                                                    '—'}
                                                            </button>
                                                        ),
                                                },
                                            ].map((item, i) => (
                                                <div
                                                    key={i}
                                                    className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                >
                                                    <label className="text-xs font-medium text-zinc-400">
                                                        {item.label}
                                                    </label>
                                                    <div className="text-sm font-medium text-zinc-200">
                                                        {item.value}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                        </div>

                                        <div className="rounded-xl border border-white/5 bg-white/5 p-5">
                                            <div className="mb-4 flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Family
                                                </h3>
                                            </div>

                                            <div className="space-y-3">
                                                {[
                                                    {
                                                        key: 'spouse_name',
                                                        label: 'Spouse name',
                                                        input: (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.spouse_name}
                                                                onChange={(e) => form.setData('spouse_name', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ),
                                                        value: form.data.spouse_name || employee.spouse_name || '—',
                                                    },
                                                    {
                                                        key: 'spouse_birthdate',
                                                        label: 'Spouse birthdate',
                                                        input: (
                                                            <Input
                                                                type="date"
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.spouse_birthdate}
                                                                onChange={(e) => form.setData('spouse_birthdate', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ),
                                                        value: form.data.spouse_birthdate || employee.spouse_birthdate || '—',
                                                    },
                                                    {
                                                        key: 'dependent_children_count',
                                                        label: 'Dependent children',
                                                        input: (
                                                            <Input
                                                                inputMode="numeric"
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={String(form.data.dependent_children_count ?? '')}
                                                                onChange={(e) => form.setData('dependent_children_count', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ),
                                                        value:
                                                            String(form.data.dependent_children_count ?? '') ||
                                                            (employee.dependent_children_count === null || employee.dependent_children_count === undefined
                                                                ? '—'
                                                                : String(employee.dependent_children_count)),
                                                    },
                                                ].map((row) => (
                                                    <div
                                                        key={row.key}
                                                        className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                    >
                                                        <label className="text-xs font-medium text-zinc-400">
                                                            {row.label}
                                                        </label>
                                                        {activeField === row.key ? (
                                                            <div>{row.input}</div>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit(row.key)}
                                                            >
                                                                {row.value}
                                                            </button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>

                                        <div className="rounded-xl border border-white/5 bg-white/5 p-5">
                                            <div className="mb-4 flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Location
                                                </h3>
                                            </div>

                                            <div className="space-y-3">
                                                {[
                                                    {
                                                        key: 'nearest_airport',
                                                        label: 'Nearest airport',
                                                        input: (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.nearest_airport}
                                                                onChange={(e) => form.setData('nearest_airport', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ),
                                                        value: form.data.nearest_airport || employee.nearest_airport || '—',
                                                    },
                                                    {
                                                        key: 'address',
                                                        label: 'Address',
                                                        input: (
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.address}
                                                                onChange={(e) => form.setData('address', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                        ),
                                                        value: form.data.address || employee.address || '—',
                                                    },
                                                ].map((row) => (
                                                    <div
                                                        key={row.key}
                                                        className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                    >
                                                        <label className="text-xs font-medium text-zinc-400">
                                                            {row.label}
                                                        </label>
                                                        {activeField === row.key ? (
                                                            <div>{row.input}</div>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit(row.key)}
                                                            >
                                                                {row.value}
                                                            </button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="space-y-6">
                                        <div className="rounded-xl border border-white/5 bg-white/5 p-5">
                                        <div className="mb-4 flex items-center justify-between">
                                            <h3 className="text-sm font-semibold text-zinc-200">
                                                Citizenship
                                            </h3>
                                        </div>

                                        <div className="space-y-4">
                                            <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                <label className="text-xs font-medium text-zinc-400">
                                                    Nationality (Country)
                                                </label>
                                                {activeField === 'nationality_id' ? (
                                                    <div>
                                                        <select
                                                            className="w-full h-10 rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
                                                            value={form.data.nationality_id}
                                                            onChange={(e) => form.setData('nationality_id', e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        >
                                                            <option value="">—</option>
                                                            {countries.map((c) => (
                                                                <option key={c.id} value={String(c.id)}>
                                                                    {c.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {form.errors.nationality_id ? (
                                                            <div className="text-xs text-destructive mt-1">
                                                                {form.errors.nationality_id}
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                        onClick={() => beginEdit('nationality_id')}
                                                    >
                                                        {countries.find((c) => String(c.id) === String(form.data.nationality_id || employee.nationality_id || ''))?.name ??
                                                            employee.nationality_ref?.name ??
                                                            '—'}
                                                    </button>
                                                )}
                                            </div>

                                            {[
                                                {
                                                    key: 'passport_number',
                                                    label: 'Passport No',
                                                    value: form.data.passport_number || employee.passport_number || '—',
                                                },
                                                {
                                                    key: 'emirates_id',
                                                    label: 'Emirates ID',
                                                    value: form.data.emirates_id || employee.emirates_id || '—',
                                                },
                                                {
                                                    key: 'labor_card_number',
                                                    label: 'Labor card number',
                                                    value: form.data.labor_card_number || employee.labor_card_number || '—',
                                                },
                                            ].map((item) => (
                                                <div
                                                    key={item.key}
                                                    className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                >
                                                    <label className="text-xs font-medium text-zinc-400">
                                                        {item.label}
                                                    </label>
                                                    {activeField === item.key ? (
                                                        <Input
                                                            className="h-10 rounded-xl border-white/5 bg-white/5"
                                                            value={(form.data as any)[item.key]}
                                                            onChange={(e) => form.setData(item.key as any, e.target.value)}
                                                            onBlur={() => setActiveField(null)}
                                                            autoFocus
                                                        />
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit(item.key)}
                                                        >
                                                            {item.value}
                                                        </button>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                        </div>
                                    </div>
                                </div>
                            </TabsContent>

                            <TabsContent value="contract" className="mt-6">
                                <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                                    <div className="space-y-6 xl:col-span-7">
                                        <div className="rounded-xl border border-white/5 bg-white/5 p-5">
                                            <div className="mb-4 flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Contract
                                                </h3>
                                            </div>

                                            <div className="space-y-4">
                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">
                                                        Contract type
                                                        {requiredDot('contract_type')}
                                                    </label>
                                                    {activeField === 'contract_type' ? (
                                                        <div>
                                                            <select
                                                                className="w-full h-10 rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
                                                                value={form.data.contract_type}
                                                                onChange={(e) => form.setData('contract_type', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            >
                                                                <option value="limited">Limited</option>
                                                                <option value="unlimited">Unlimited</option>
                                                                <option value="part_time">Part Time</option>
                                                                <option value="contract">Contract</option>
                                                            </select>
                                                            {form.errors.contract_type ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.contract_type}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('contract_type')}
                                                        >
                                                            {form.data.contract_type || contract?.contract_type || '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">
                                                        Start date
                                                        {requiredDot('start_date')}
                                                    </label>
                                                    {activeField === 'start_date' ? (
                                                        <div>
                                                            <Input
                                                                type="date"
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.start_date}
                                                                onChange={(e) => form.setData('start_date', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.start_date ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.start_date}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('start_date')}
                                                        >
                                                            {form.data.start_date || contract?.start_date || '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">End date</label>
                                                    {activeField === 'end_date' ? (
                                                        <div>
                                                            <Input
                                                                type="date"
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.end_date}
                                                                onChange={(e) => form.setData('end_date', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.end_date ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.end_date}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('end_date')}
                                                        >
                                                            {form.data.end_date || contract?.end_date || '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">Probation end date</label>
                                                    {activeField === 'probation_end_date' ? (
                                                        <div>
                                                            <Input
                                                                type="date"
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.probation_end_date}
                                                                onChange={(e) => form.setData('probation_end_date', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.probation_end_date ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.probation_end_date}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('probation_end_date')}
                                                        >
                                                            {form.data.probation_end_date || contract?.probation_end_date || '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">Labor contract ID</label>
                                                    {activeField === 'labor_contract_id' ? (
                                                        <div>
                                                            <Input
                                                                className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                value={form.data.labor_contract_id}
                                                                onChange={(e) => form.setData('labor_contract_id', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            />
                                                            {form.errors.labor_contract_id ? (
                                                                <div className="text-xs text-destructive mt-1">{form.errors.labor_contract_id}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('labor_contract_id')}
                                                        >
                                                            {form.data.labor_contract_id || contract?.labor_contract_id || '—'}
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="space-y-6 xl:col-span-5">
                                        <div className="rounded-xl border border-white/5 bg-white/5 p-5">
                                            <div className="mb-4 flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Salary
                                                </h3>
                                            </div>

                                            <div className="space-y-4">
                                                {(
                                                    [
                                                        { key: 'basic_salary', label: 'Basic salary' },
                                                        { key: 'housing_allowance', label: 'Housing allowance' },
                                                        { key: 'transport_allowance', label: 'Transport allowance' },
                                                        { key: 'other_allowances', label: 'Other allowances' },
                                                    ] as const
                                                ).map((row) => (
                                                    <div
                                                        key={row.key}
                                                        className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                    >
                                                        <label className="text-xs font-medium text-zinc-400">
                                                            {row.label}
                                                        </label>
                                                        {activeField === row.key ? (
                                                            <div>
                                                                <Input
                                                                    inputMode="decimal"
                                                                    className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                    value={String((form.data as any)[row.key] ?? '')}
                                                                    onChange={(e) => form.setData(row.key as any, e.target.value)}
                                                                    onBlur={() => setActiveField(null)}
                                                                    autoFocus
                                                                />
                                                                {(form.errors as any)[row.key] ? (
                                                                    <div className="text-xs text-destructive mt-1">
                                                                        {(form.errors as any)[row.key]}
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                                onClick={() => beginEdit(row.key)}
                                                            >
                                                                {String((form.data as any)[row.key] ?? '') ||
                                                                ((contract as any)?.[row.key] === null || (contract as any)?.[row.key] === undefined
                                                                    ? '—'
                                                                    : String((contract as any)?.[row.key]))}
                                                            </button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </TabsContent>

                            <TabsContent value="documents" className="mt-6">
                                <div className="rounded-xl border border-white/5 bg-white/5 p-5">
                                    <div className="mb-4 flex items-center justify-between">
                                        <h3 className="text-sm font-semibold text-zinc-200">
                                            Documents
                                        </h3>
                                        <div className="text-xs font-medium text-zinc-500">
                                            {documents.length} total
                                        </div>
                                    </div>

                                    {documents.length === 0 ? (
                                        <div className="py-10 text-center text-sm text-zinc-500">
                                            No documents uploaded.
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[900px] text-left">
                                                <thead>
                                                    <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                                        <th className="py-2 pr-4">
                                                            Title
                                                        </th>
                                                        <th className="py-2 pr-4">
                                                            Type
                                                        </th>
                                                        <th className="py-2 pr-4">
                                                            Number
                                                        </th>
                                                        <th className="py-2 pr-4">
                                                            Issue
                                                        </th>
                                                        <th className="py-2 pr-4">
                                                            Expiry
                                                        </th>
                                                        <th className="py-2 pr-4">
                                                            Status
                                                        </th>
                                                        <th className="py-2 pr-4">
                                                            File
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-white/5">
                                                    {documents.map((doc) => {
                                                        const fileUrl = doc.file_path.startsWith(
                                                            'http',
                                                        )
                                                            ? doc.file_path
                                                            : `/storage/${doc.file_path.replace(/^\/+/, '')}`;

                                                        return (
                                                            <tr
                                                                key={doc.id}
                                                                className="text-sm text-zinc-200"
                                                            >
                                                                <td className="py-3 pr-4 font-medium">
                                                                    {doc.title ||
                                                                        '—'}
                                                                </td>
                                                                <td className="py-3 pr-4 text-zinc-400">
                                                                    {doc.document_type ||
                                                                        doc.type ||
                                                                        '—'}
                                                                </td>
                                                                <td className="py-3 pr-4 text-zinc-400">
                                                                    {doc.document_number ||
                                                                        '—'}
                                                                </td>
                                                                <td className="py-3 pr-4 text-zinc-400">
                                                                    {doc.issue_date ||
                                                                        '—'}
                                                                </td>
                                                                <td className="py-3 pr-4 text-zinc-400">
                                                                    {doc.expiry_date ||
                                                                        '—'}
                                                                </td>
                                                                <td className="py-3 pr-4">
                                                                    <span className="inline-flex rounded-md border border-white/10 bg-white/5 px-2 py-0.5 text-xs font-medium text-zinc-300">
                                                                        {doc.status ||
                                                                            '—'}
                                                                    </span>
                                                                </td>
                                                                <td className="py-3 pr-4">
                                                                    <a
                                                                        href={
                                                                            fileUrl
                                                                        }
                                                                        target="_blank"
                                                                        rel="noreferrer"
                                                                        className="text-xs font-semibold text-primary hover:underline"
                                                                    >
                                                                        View
                                                                    </a>
                                                                </td>
                                                            </tr>
                                                        );
                                                    })}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>
                            </TabsContent>
                        </Tabs>
                    </div>
                    </div>
                </div>
            </Main>

            <style>{`
                .hide-scrollbar::-webkit-scrollbar {
                    display: none;
                }
                .hide-scrollbar {
                    -ms-overflow-style: none;
                    scrollbar-width: none;
                }
            `}</style>
        </>
    );
}
