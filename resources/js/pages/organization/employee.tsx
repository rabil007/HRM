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
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { toast } from '@/lib/toast';
import { EmployeeBankTab } from '@/pages/organization/_components/employee-bank-tab';
import { EmployeeContractTab } from '@/pages/organization/_components/employee-contract-tab';
import { EmployeeDocumentsTab } from '@/pages/organization/_components/employee-documents-tab';
import { EmployeeEducationTab } from '@/pages/organization/_components/employee-education-tab';
import { EmployeeHeaderCard } from '@/pages/organization/_components/employee-header-card';
import { EmployeeLanguagesTab } from '@/pages/organization/_components/employee-languages-tab';
import { EmployeePersonalTab } from '@/pages/organization/_components/employee-personal-tab';
import { EmployeeVaccinationTab } from '@/pages/organization/_components/employee-vaccination-tab';
import { EmployeeWorkExperienceTab } from '@/pages/organization/_components/employee-work-experience-tab';
import type {
    EmployeePageProps,
    EmployeeTab,
} from '@/pages/organization/employee-page.types';

export default function EmployeeDetails({
    employee,
    contract,
    documents,
    education_qualifications,
    work_experiences,
    vaccinations,
    languages,
    document_types,
    can,
    branches,
    departments,
    positions,
    managers,
    users,
    countries,
    religions,
    genders,
    banks,
    ranks,
    recent_activity,
}: EmployeePageProps) {
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
    void recent_activity;

    const [activeField, setActiveField] = useState<string | null>(null);
    const [tabValue, setTabValue] = useState<EmployeeTab>(() => {
        if (typeof window === 'undefined') {
            return 'personal';
        }

        if (window.location.hash === '#documents') {
            return 'documents';
        }

        if (window.location.hash === '#education') {
            return 'education';
        }

        if (window.location.hash === '#work-experience') {
            return 'work_experience';
        }

        if (window.location.hash === '#vaccination') {
            return 'vaccination';
        }

        if (window.location.hash === '#languages') {
            return 'languages';
        }

        return 'personal';
    });
    const [pendingTab, setPendingTab] = useState<EmployeeTab | null>(null);
    const [unsavedDialogOpen, setUnsavedDialogOpen] = useState(false);

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
            (key) => {
                return (
                    String(form.data[key] ?? '') !==
                    String(initialAll[key] ?? '')
                );
            },
        );
    }, [form.data, initialAll]);

    const requiredFields = useMemo(() => {
        return new Set(['employee_no', 'name', 'start_date', 'contract_type']);
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
        return String(form.data.name ?? '').trim() || 'Employee';
    }, [form.data.name]);

    const tabs = [
        { id: 'personal', label: 'Personal', count: null },
        { id: 'contract', label: 'Contract', count: null },
        {
            id: 'bank',
            label: 'Bank',
            count: form.data.bank_id || form.data.iban ? 1 : null,
        },
        {
            id: 'education',
            label: 'Education',
            count: education_qualifications.length || null,
        },
        {
            id: 'work_experience',
            label: 'Work experience',
            count: work_experiences.length || null,
        },
        {
            id: 'vaccination',
            label: 'Vaccination',
            count: vaccinations.length || null,
        },
        {
            id: 'languages',
            label: 'Languages',
            count: languages.length || null,
        },
        {
            id: 'documents',
            label: 'Documents',
            count: documents.length || null,
        },
    ] satisfies Array<{ id: EmployeeTab; label: string; count: number | null }>;

    useEffect(() => {
        if (
            window.location.hash === '#documents' ||
            window.location.hash === '#education' ||
            window.location.hash === '#work-experience' ||
            window.location.hash === '#vaccination' ||
            window.location.hash === '#languages'
        ) {
            window.history.replaceState(null, '', window.location.pathname);
        }
    }, []);

    const saveChanges = (afterSuccess?: () => void) => {
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
                toast.error('Please fill the required fields before saving.');
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

        form.put(`/organization/employees/${employee.id}`, {
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
    };

    const discardChanges = () => {
        form.setData(initialAll);
        form.clearErrors();
        setActiveField(null);
    };

    const handleTabChange = (next: EmployeeTab) => {
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
            <Main className="min-h-screen bg-[radial-gradient(circle_at_top_right,rgba(99,102,241,0.10),transparent_28%),radial-gradient(circle_at_bottom_left,rgba(16,185,129,0.08),transparent_26%)] p-0">
                {/* Main Content Area - Full Width */}
                <div className="w-full px-4 py-5 md:px-6 md:py-6 xl:px-8">
                    <div className="w-full space-y-6">
                        {canUpdate && isDirty ? (
                            <div className="sticky top-4 z-20 rounded-2xl border border-amber-500/20 bg-amber-500/10 p-3 shadow-lg shadow-black/20 backdrop-blur-xl">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="text-sm font-semibold text-amber-100">
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
                        <AlertDialog
                            open={unsavedDialogOpen}
                            onOpenChange={setUnsavedDialogOpen}
                        >
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>
                                        Unsaved changes
                                    </AlertDialogTitle>
                                    <AlertDialogDescription>
                                        You have unsaved changes. What would you
                                        like to do?
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
                            ranks={ranks}
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
                        <div
                            id="employee-tabs"
                            className="rounded-[1.75rem] border border-white/10 bg-card/60 p-2 shadow-2xl shadow-black/10 backdrop-blur-xl"
                        >
                            <Tabs
                                value={tabValue}
                                onValueChange={(v) =>
                                    handleTabChange(v as EmployeeTab)
                                }
                                className="w-full"
                            >
                                <TabsList className="hide-scrollbar h-auto w-full flex-nowrap justify-start gap-2 overflow-x-auto rounded-2xl border border-white/10 bg-black/10 p-1.5">
                                    {tabs.map((tab) => (
                                        <TabsTrigger
                                            key={tab.id}
                                            value={tab.id}
                                            className="shrink-0 rounded-xl border border-transparent bg-transparent px-4 py-2.5 text-xs font-bold tracking-wide whitespace-nowrap text-zinc-400 transition-colors hover:bg-white/5 hover:text-zinc-200 data-[state=active]:border-white/10 data-[state=active]:bg-white/10 data-[state=active]:text-white data-[state=active]:shadow-lg data-[state=active]:shadow-black/20"
                                        >
                                            {tab.label}
                                            {tab.count !== null && (
                                                <span className="ml-1.5 rounded-md bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold text-zinc-400 tabular-nums">
                                                    {tab.count}
                                                </span>
                                            )}
                                        </TabsTrigger>
                                    ))}
                                </TabsList>

                                <EmployeePersonalTab
                                    employee={employee}
                                    countries={countries}
                                    form={form}
                                    activeField={activeField}
                                    setActiveField={setActiveField}
                                    beginEdit={beginEdit}
                                />
                                <EmployeeContractTab
                                    contract={contract}
                                    form={form}
                                    activeField={activeField}
                                    setActiveField={setActiveField}
                                    beginEdit={beginEdit}
                                    requiredDot={requiredDot}
                                />
                                <EmployeeBankTab
                                    employee={employee}
                                    banks={banks}
                                    form={form}
                                    activeField={activeField}
                                    setActiveField={setActiveField}
                                    beginEdit={beginEdit}
                                />
                                <EmployeeEducationTab
                                    employeeId={employee.id}
                                    education_qualifications={
                                        education_qualifications
                                    }
                                    countries={countries}
                                    canManage={can.education_manage}
                                />
                                <EmployeeWorkExperienceTab
                                    employeeId={employee.id}
                                    work_experiences={work_experiences}
                                    canManage={can.work_experience_manage}
                                />
                                <EmployeeVaccinationTab
                                    employeeId={employee.id}
                                    vaccinations={vaccinations}
                                    countries={countries}
                                    canManage={can.vaccination_manage}
                                />
                                <EmployeeLanguagesTab
                                    employeeId={employee.id}
                                    languages={languages}
                                    canManage={can.languages_manage}
                                />

                                <EmployeeDocumentsTab
                                    employee={{
                                        id: employee.id,
                                        name: employee.name,
                                    }}
                                    documents={documents}
                                    document_types={document_types}
                                    can={{
                                        documents_upload: can.documents_upload,
                                        documents_delete: can.documents_delete,
                                    }}
                                />
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
