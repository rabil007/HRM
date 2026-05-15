import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { destroy, store, update } from '@/actions/App/Http/Controllers/Organization/EmployeeEducationQualificationController';
import {
    destroy as destroyVaccination,
    store as storeVaccination,
    update as updateVaccination,
} from '@/actions/App/Http/Controllers/Organization/EmployeeVaccinationController';
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
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { toast } from '@/lib/toast';
import type {
    EducationQualificationItem,
    EmployeePageProps,
    EmployeeTab,
    VaccinationItem,
    WorkExperienceItem,
} from '@/pages/organization/employee-page.types';
import { EmployeeDocumentsTab } from '@/pages/organization/_components/employee-documents-tab';
import { EmployeeHeaderCard } from '@/pages/organization/_components/employee-header-card';
import { VaccinationImportDialog } from '@/pages/organization/_components/vaccination-import-dialog';
import { WorkExperienceImportDialog } from '@/pages/organization/_components/work-experience-import-dialog';
import {
    destroy as destroyWorkExperience,
    store as storeWorkExperience,
    update as updateWorkExperience,
} from '@/actions/App/Http/Controllers/Organization/EmployeeWorkExperienceController';

export default function EmployeeDetails({
    employee,
    contract,
    documents,
    education_qualifications,
    work_experiences,
    vaccinations,
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

        return 'personal';
    });
    const [pendingTab, setPendingTab] = useState<EmployeeTab | null>(null);
    const [unsavedDialogOpen, setUnsavedDialogOpen] = useState(false);

    const [educationDialogOpen, setEducationDialogOpen] = useState(false);
    const [editingEducation, setEditingEducation] = useState<EducationQualificationItem | null>(null);
    const [deleteEducationId, setDeleteEducationId] = useState<number | null>(null);
    const [workExperienceDialogOpen, setWorkExperienceDialogOpen] = useState(false);
    const [workExperienceImportOpen, setWorkExperienceImportOpen] = useState(false);
    const [editingWorkExperience, setEditingWorkExperience] = useState<WorkExperienceItem | null>(null);
    const [deleteWorkExperienceId, setDeleteWorkExperienceId] = useState<number | null>(null);
    const [vaccinationDialogOpen, setVaccinationDialogOpen] = useState(false);
    const [vaccinationImportOpen, setVaccinationImportOpen] = useState(false);
    const [editingVaccination, setEditingVaccination] = useState<VaccinationItem | null>(null);
    const [deleteVaccinationId, setDeleteVaccinationId] = useState<number | null>(null);

    const educationForm = useForm({
        certificate: '',
        issue_date: '',
        university: '',
        country_id: '',
    });

    const workExperienceForm = useForm({
        company_name: '',
        job_title: '',
        date_from: '',
        date_to: '',
        responsibility: '',
    });

    const vaccinationForm = useForm({
        vaccination_name: '',
        country_id: '',
        first_dose_date: '',
        second_dose_date: '',
        booster_dose_date: '',
    });

    const initialPersonal = useMemo(
        () => ({
            employee_no: employee.employee_no ?? '',
            name: employee.name ?? '',
            branch_id: employee.branch?.id ? String(employee.branch.id) : '',
            department_id: employee.department?.id ? String(employee.department.id) : '',
            position_id: employee.position?.id ? String(employee.position.id) : '',
            rank_id: employee.rank_id ? String(employee.rank_id) : '',
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
        [
            employee.account_name,
            employee.bank_id,
            employee.iban,
        ],
    );

    const initialAll = useMemo(() => ({ ...initialPersonal, ...initialContract, ...initialBank }), [initialBank, initialContract, initialPersonal]);

    const form = useForm(initialAll);

    const isDirty = useMemo(() => {
        return (Object.keys(initialAll) as Array<keyof typeof initialAll>).some((key) => {
            return String(form.data[key] ?? '') !== String(initialAll[key] ?? '');
        });
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

    const formatWorkExpDate = (iso: string | null): string => {
        if (!iso) {
            return '—';
        }

        const parts = iso.split('-');

        if (parts.length !== 3) {
            return iso;
        }

        const y = Number(parts[0]);
        const m = Number(parts[1]);
        const d = Number(parts[2]);

        if (!y || !m || !d) {
            return iso;
        }

        return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    };

    const tabs = [
        { id: 'personal', label: 'Personal', count: null },
        { id: 'contract', label: 'Contract', count: null },
        { id: 'bank', label: 'Bank', count: form.data.bank_id || form.data.iban ? 1 : null },
        { id: 'education', label: 'Education', count: education_qualifications.length || null },
        { id: 'work_experience', label: 'Work experience', count: work_experiences.length || null },
        { id: 'vaccination', label: 'Vaccination', count: vaccinations.length || null },
        { id: 'documents', label: 'Documents', count: documents.length || null },
    ] satisfies Array<{ id: EmployeeTab; label: string; count: number | null }>;

    useEffect(() => {
        if (
            window.location.hash === '#documents' ||
            window.location.hash === '#education' ||
            window.location.hash === '#work-experience' ||
            window.location.hash === '#vaccination'
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
                toast.error(typeof first === 'string' && first.length ? first : 'Failed to save changes.');
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
                        <div id="employee-tabs" className="rounded-[1.75rem] border border-white/10 bg-card/60 p-2 shadow-2xl shadow-black/10 backdrop-blur-xl">
                            <Tabs value={tabValue} onValueChange={(v) => handleTabChange(v as EmployeeTab)} className="w-full">
                            <TabsList className="hide-scrollbar h-auto w-full flex-nowrap justify-start gap-2 overflow-x-auto rounded-2xl border border-white/10 bg-black/10 p-1.5">
                                {tabs.map((tab) => (
                                    <TabsTrigger
                                        key={tab.id}
                                        value={tab.id}
                                        className="shrink-0 rounded-xl border border-transparent bg-transparent px-4 py-2.5 text-xs font-bold tracking-wide whitespace-nowrap text-zinc-400 transition-colors hover:bg-white/5 hover:text-zinc-200 data-[state=active]:border-white/10 data-[state=active]:bg-white/10 data-[state=active]:text-white data-[state=active]:shadow-lg data-[state=active]:shadow-black/20"
                                    >
                                        {tab.label}
                                        {tab.count !== null && (
                                            <span className="ml-1.5 rounded-md bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-zinc-400">
                                                {tab.count}
                                            </span>
                                        )}
                                    </TabsTrigger>
                                ))}
                            </TabsList>

                            <TabsContent
                                value="personal"
                                className="mt-6"
                            >
                                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2 2xl:grid-cols-3">
                                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
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
                                                            <div className="mt-1 text-xs text-destructive">{form.errors.personal_email}</div>
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
                                                            <div className="mt-1 text-xs text-destructive">{form.errors.phone_home_country}</div>
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
                                                            <div className="mt-1 text-xs text-destructive">{form.errors.cv_source}</div>
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

                                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
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

                                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
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

                                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
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

                                    <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl xl:col-span-2 2xl:col-span-3">
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
                                                            className="h-10 w-full rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
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
                                                            <div className="mt-1 text-xs text-destructive">
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
                            </TabsContent>

                            <TabsContent value="contract" className="mt-6">
                                <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                                    <div className="space-y-6 xl:col-span-7">
                                        <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
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
                                        <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
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

                            <TabsContent value="bank" className="mt-6">
                                <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                                    <div className="space-y-6 xl:col-span-7">
                                        <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                            <div className="mb-4 flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Bank account
                                                </h3>
                                            </div>

                                            <div className="space-y-4">
                                                <div className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4">
                                                    <label className="text-xs font-medium text-zinc-400">Bank</label>
                                                    {activeField === 'bank_id' ? (
                                                        <div>
                                                            <select
                                                                className="h-10 w-full rounded-xl border border-white/5 bg-white/5 px-3 text-sm text-zinc-200 outline-none"
                                                                value={form.data.bank_id}
                                                                onChange={(e) => form.setData('bank_id', e.target.value)}
                                                                onBlur={() => setActiveField(null)}
                                                                autoFocus
                                                            >
                                                                <option value="">—</option>
                                                                {banks.map((bank) => (
                                                                    <option key={bank.id} value={String(bank.id)}>
                                                                        {bank.name}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            {form.errors.bank_id ? (
                                                                <div className="mt-1 text-xs text-destructive">{form.errors.bank_id}</div>
                                                            ) : null}
                                                        </div>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="text-left text-sm font-medium text-zinc-200 hover:text-white"
                                                            onClick={() => beginEdit('bank_id')}
                                                        >
                                                            {banks.find((bank) => String(bank.id) === String(form.data.bank_id || employee.bank_id || ''))?.name ??
                                                                employee.bank?.name ??
                                                                '—'}
                                                        </button>
                                                    )}
                                                </div>

                                                {[
                                                    {
                                                        key: 'account_name',
                                                        label: 'Account holder',
                                                        value: form.data.account_name || employee.account_name || '—',
                                                    },
                                                    {
                                                        key: 'iban',
                                                        label: 'IBAN',
                                                        value: form.data.iban || employee.iban || '—',
                                                    },
                                                ].map((item) => (
                                                    <div
                                                        key={item.key}
                                                        className="grid grid-cols-1 gap-1 sm:grid-cols-[180px_1fr] sm:items-center sm:gap-4"
                                                    >
                                                        <label className="text-xs font-medium text-zinc-400">{item.label}</label>
                                                        {activeField === item.key ? (
                                                            <div>
                                                                <Input
                                                                    className="h-10 rounded-xl border-white/5 bg-white/5"
                                                                    value={(form.data as any)[item.key]}
                                                                    onChange={(e) => form.setData(item.key as any, e.target.value)}
                                                                    onBlur={() => setActiveField(null)}
                                                                    autoFocus
                                                                />
                                                                {(form.errors as any)[item.key] ? (
                                                                    <div className="mt-1 text-xs text-destructive">
                                                                        {(form.errors as any)[item.key]}
                                                                    </div>
                                                                ) : null}
                                                            </div>
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

                                    <div className="space-y-6 xl:col-span-5">
                                        <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                            <div className="mb-4 flex items-center justify-between">
                                                <h3 className="text-sm font-semibold text-zinc-200">
                                                    Payroll payment
                                                </h3>
                                            </div>

                                            <div className="space-y-3">
                                                <div className="rounded-xl border border-white/5 bg-black/10 p-4">
                                                    <div className="text-xs font-medium text-zinc-500">Primary bank</div>
                                                    <div className="mt-1 text-sm font-semibold text-zinc-200">
                                                        {banks.find((bank) => String(bank.id) === String(form.data.bank_id || employee.bank_id || ''))?.name ??
                                                            employee.bank?.name ??
                                                            'Not selected'}
                                                    </div>
                                                </div>
                                                <div className="rounded-xl border border-white/5 bg-black/10 p-4">
                                                    <div className="text-xs font-medium text-zinc-500">Account holder</div>
                                                    <div className="mt-1 text-sm font-semibold text-zinc-200">
                                                        {form.data.account_name || employee.account_name || '—'}
                                                    </div>
                                                </div>
                                                <div className="rounded-xl border border-white/5 bg-black/10 p-4">
                                                    <div className="text-xs font-medium text-zinc-500">IBAN</div>
                                                    <div className="mt-1 break-all font-mono text-sm font-semibold text-zinc-200">
                                                        {form.data.iban || employee.iban || '—'}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </TabsContent>

                            <TabsContent value="education" className="mt-6">
                                <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <h3 className="text-sm font-semibold text-zinc-200">
                                            Education qualifications
                                            <span className="ml-2 text-xs font-normal text-zinc-500">{education_qualifications.length} total</span>
                                        </h3>
                                        {can.education_manage && (
                                            <Button
                                                size="sm"
                                                className="h-8 gap-1.5 text-xs"
                                                type="button"
                                                onClick={() => {
                                                    educationForm.reset();
                                                    educationForm.clearErrors();
                                                    setEditingEducation(null);
                                                    setEducationDialogOpen(true);
                                                }}
                                            >
                                                + Add qualification
                                            </Button>
                                        )}
                                    </div>

                                    {education_qualifications.length === 0 ? (
                                        <div className="py-10 text-center text-sm text-zinc-500">
                                            No qualifications recorded.
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[680px] text-left">
                                                <thead>
                                                    <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                                        <th className="py-2 pr-4">Certificate</th>
                                                        <th className="py-2 pr-4">Issue date</th>
                                                        <th className="py-2 pr-4">University</th>
                                                        <th className="py-2 pr-4">Country</th>
                                                        {can.education_manage ? <th className="py-2 pr-4" /> : null}
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-white/5">
                                                    {education_qualifications.map((row) => (
                                                        <tr key={row.id} className="text-sm text-zinc-200">
                                                            <td className="py-3 pr-4 font-medium">{row.certificate}</td>
                                                            <td className="py-3 pr-4 font-mono text-xs text-zinc-400">{row.issue_date ?? '—'}</td>
                                                            <td className="py-3 pr-4 text-zinc-300">{row.university ?? '—'}</td>
                                                            <td className="py-3 pr-4 text-xs text-zinc-400">{row.country_name ?? '—'}</td>
                                                            {can.education_manage ? (
                                                                <td className="py-3 pr-4">
                                                                    <div className="flex items-center gap-2">
                                                                        <button
                                                                            type="button"
                                                                            className="text-xs text-zinc-400 transition-colors hover:text-zinc-200"
                                                                            onClick={() => {
                                                                                setEditingEducation(row);
                                                                                educationForm.setData({
                                                                                    certificate: row.certificate,
                                                                                    issue_date: row.issue_date ?? '',
                                                                                    university: row.university ?? '',
                                                                                    country_id: row.country_id ? String(row.country_id) : '',
                                                                                });
                                                                                educationForm.clearErrors();
                                                                                setEducationDialogOpen(true);
                                                                            }}
                                                                        >
                                                                            Edit
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            className="text-xs text-red-400/60 transition-colors hover:text-red-400"
                                                                            onClick={() => setDeleteEducationId(row.id)}
                                                                        >
                                                                            Delete
                                                                        </button>
                                                                    </div>
                                                                </td>
                                                            ) : null}
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>

                                <Dialog
                                    open={educationDialogOpen}
                                    onOpenChange={(open) => {
                                        setEducationDialogOpen(open);

                                        if (!open) {
                                            educationForm.reset();
                                            educationForm.clearErrors();
                                            setEditingEducation(null);
                                        }
                                    }}
                                >
                                    <DialogContent className="sm:max-w-md">
                                        <DialogHeader>
                                            <DialogTitle>{editingEducation ? 'Edit qualification' : 'Add qualification'}</DialogTitle>
                                        </DialogHeader>
                                        <div className="space-y-4 py-2">
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Certificate</Label>
                                                <Input
                                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                    value={educationForm.data.certificate}
                                                    onChange={(e) => educationForm.setData('certificate', e.target.value)}
                                                />
                                                {educationForm.errors.certificate ? (
                                                    <p className="text-xs text-destructive">{educationForm.errors.certificate}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Issue date</Label>
                                                <Input
                                                    type="date"
                                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                    value={educationForm.data.issue_date}
                                                    onChange={(e) => educationForm.setData('issue_date', e.target.value)}
                                                />
                                                {educationForm.errors.issue_date ? (
                                                    <p className="text-xs text-destructive">{educationForm.errors.issue_date}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">University / institution</Label>
                                                <Input
                                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                    value={educationForm.data.university}
                                                    onChange={(e) => educationForm.setData('university', e.target.value)}
                                                />
                                                {educationForm.errors.university ? (
                                                    <p className="text-xs text-destructive">{educationForm.errors.university}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Country</Label>
                                                <select
                                                    value={educationForm.data.country_id}
                                                    onChange={(e) => educationForm.setData('country_id', e.target.value)}
                                                    className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                                                >
                                                    <option value="">—</option>
                                                    {countries.map((c) => (
                                                        <option key={c.id} value={String(c.id)}>
                                                            {c.name}
                                                        </option>
                                                    ))}
                                                </select>
                                                {educationForm.errors.country_id ? (
                                                    <p className="text-xs text-destructive">{educationForm.errors.country_id}</p>
                                                ) : null}
                                            </div>
                                        </div>
                                        <DialogFooter>
                                            <Button variant="outline" size="sm" type="button" onClick={() => setEducationDialogOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button
                                                size="sm"
                                                type="button"
                                                disabled={educationForm.processing}
                                                onClick={() => {
                                                    educationForm.clearErrors();
                                                    educationForm.transform((data) => ({
                                                        certificate: data.certificate.trim(),
                                                        issue_date: data.issue_date === '' ? null : data.issue_date,
                                                        university: data.university.trim() === '' ? null : data.university.trim(),
                                                        country_id: data.country_id === '' ? null : Number(data.country_id),
                                                    }));

                                                    const url = editingEducation
                                                        ? update.url({
                                                            employee: employee.id,
                                                            qualification: editingEducation.id,
                                                        })
                                                        : store.url({ employee: employee.id });

                                                    if (editingEducation) {
                                                        educationForm.put(url, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setEducationDialogOpen(false);
                                                                educationForm.reset();
                                                                setEditingEducation(null);
                                                                toast.success('Qualification updated.');
                                                            },
                                                        });
                                                    } else {
                                                        educationForm.post(url, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setEducationDialogOpen(false);
                                                                educationForm.reset();
                                                                toast.success('Qualification added.');
                                                            },
                                                        });
                                                    }
                                                }}
                                            >
                                                {educationForm.processing ? 'Saving…' : 'Save'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>

                                <AlertDialog
                                    open={!!deleteEducationId}
                                    onOpenChange={(open) => {
                                        if (!open) {
                                            setDeleteEducationId(null);
                                        }
                                    }}
                                >
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Remove qualification?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                This education record will be permanently removed.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                onClick={() => {
                                                    if (!deleteEducationId) {
                                                        return;
                                                    }

                                                    router.delete(
                                                        destroy.url({
                                                            employee: employee.id,
                                                            qualification: deleteEducationId,
                                                        }), {
                                                        preserveScroll: true,
                                                        onSuccess: () => {
                                                            setDeleteEducationId(null);
                                                            toast.success('Qualification removed.');
                                                        },
                                                    });
                                                }}
                                            >
                                                Remove
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </TabsContent>

                            <TabsContent value="work_experience" className="mt-6">
                                <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <h3 className="text-sm font-semibold text-zinc-200">
                                            Work experience
                                            <span className="ml-2 text-xs font-normal text-zinc-500">{work_experiences.length} total</span>
                                        </h3>
                                        {can.work_experience_manage ? (
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-8 gap-1.5 text-xs"
                                                    type="button"
                                                    onClick={() => setWorkExperienceImportOpen(true)}
                                                >
                                                    Import CSV
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    className="h-8 gap-1.5 text-xs"
                                                    type="button"
                                                    onClick={() => {
                                                        workExperienceForm.reset();
                                                        workExperienceForm.clearErrors();
                                                        setEditingWorkExperience(null);
                                                        setWorkExperienceDialogOpen(true);
                                                    }}
                                                >
                                                    + Add line
                                                </Button>
                                            </div>
                                        ) : null}
                                    </div>

                                    {work_experiences.length === 0 ? (
                                        <div className="py-10 text-center text-sm text-zinc-500">
                                            No work history recorded.
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[800px] text-left">
                                                <thead>
                                                    <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                                        <th className="py-2 pr-4">Company</th>
                                                        <th className="py-2 pr-4">Job title</th>
                                                        <th className="py-2 pr-4">From</th>
                                                        <th className="py-2 pr-4">To</th>
                                                        <th className="py-2 pr-4">Responsibility</th>
                                                        <th className="py-2 pr-4">Added</th>
                                                        {can.work_experience_manage ? <th className="py-2 pr-4 text-right" /> : null}
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-white/5">
                                                    {work_experiences.map((row) => (
                                                        <tr key={row.id} className="text-sm text-zinc-200">
                                                            <td className="max-w-[200px] truncate py-3 pr-4 font-medium" title={row.company_name}>
                                                                {row.company_name}
                                                            </td>
                                                            <td className="max-w-[160px] truncate py-3 pr-4 text-zinc-300" title={row.job_title}>
                                                                {row.job_title}
                                                            </td>
                                                            <td className="whitespace-nowrap py-3 pr-4 text-xs text-zinc-400">
                                                                {formatWorkExpDate(row.date_from)}
                                                            </td>
                                                            <td className="whitespace-nowrap py-3 pr-4 text-xs text-zinc-400">
                                                                {formatWorkExpDate(row.date_to)}
                                                            </td>
                                                            <td className="max-w-[220px] truncate py-3 pr-4 text-xs text-zinc-400" title={row.responsibility ?? ''}>
                                                                {row.responsibility?.trim() ? row.responsibility : '—'}
                                                            </td>
                                                            <td className="whitespace-nowrap py-3 pr-4 text-xs text-zinc-500">
                                                                {new Date(row.created_at).toLocaleString(undefined, {
                                                                    month: 'short',
                                                                    day: 'numeric',
                                                                    hour: 'numeric',
                                                                    minute: '2-digit',
                                                                })}
                                                            </td>
                                                            {can.work_experience_manage ? (
                                                                <td className="py-3 pr-0 text-right">
                                                                    <div className="flex items-center justify-end gap-2">
                                                                        <button
                                                                            type="button"
                                                                            className="text-xs text-zinc-400 transition-colors hover:text-zinc-200"
                                                                            onClick={() => {
                                                                                setEditingWorkExperience(row);
                                                                                workExperienceForm.setData({
                                                                                    company_name: row.company_name,
                                                                                    job_title: row.job_title,
                                                                                    date_from: row.date_from ?? '',
                                                                                    date_to: row.date_to ?? '',
                                                                                    responsibility: row.responsibility ?? '',
                                                                                });
                                                                                workExperienceForm.clearErrors();
                                                                                setWorkExperienceDialogOpen(true);
                                                                            }}
                                                                        >
                                                                            Edit
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            className="text-xs text-red-400/60 transition-colors hover:text-red-400"
                                                                            onClick={() => setDeleteWorkExperienceId(row.id)}
                                                                        >
                                                                            Delete
                                                                        </button>
                                                                    </div>
                                                                </td>
                                                            ) : null}
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>

                                <Dialog
                                    open={workExperienceDialogOpen}
                                    onOpenChange={(openDialog) => {
                                        setWorkExperienceDialogOpen(openDialog);

                                        if (!openDialog) {
                                            workExperienceForm.reset();
                                            workExperienceForm.clearErrors();
                                            setEditingWorkExperience(null);
                                        }
                                    }}
                                >
                                    <DialogContent className="sm:max-w-md">
                                        <DialogHeader>
                                            <DialogTitle>{editingWorkExperience ? 'Edit work experience' : 'Add work experience'}</DialogTitle>
                                        </DialogHeader>
                                        <div className="space-y-4 py-2">
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Company name</Label>
                                                <Input
                                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                    value={workExperienceForm.data.company_name}
                                                    onChange={(e) => workExperienceForm.setData('company_name', e.target.value)}
                                                />
                                                {workExperienceForm.errors.company_name ? (
                                                    <p className="text-xs text-destructive">{workExperienceForm.errors.company_name}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Job title</Label>
                                                <Input
                                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                    value={workExperienceForm.data.job_title}
                                                    onChange={(e) => workExperienceForm.setData('job_title', e.target.value)}
                                                />
                                                {workExperienceForm.errors.job_title ? (
                                                    <p className="text-xs text-destructive">{workExperienceForm.errors.job_title}</p>
                                                ) : null}
                                            </div>
                                            <div className="grid gap-3 sm:grid-cols-2">
                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">Date from</Label>
                                                    <Input
                                                        type="date"
                                                        className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                        value={workExperienceForm.data.date_from}
                                                        onChange={(e) => workExperienceForm.setData('date_from', e.target.value)}
                                                    />
                                                    {workExperienceForm.errors.date_from ? (
                                                        <p className="text-xs text-destructive">{workExperienceForm.errors.date_from}</p>
                                                    ) : null}
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">Date to</Label>
                                                    <Input
                                                        type="date"
                                                        className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                        value={workExperienceForm.data.date_to}
                                                        onChange={(e) => workExperienceForm.setData('date_to', e.target.value)}
                                                    />
                                                    {workExperienceForm.errors.date_to ? (
                                                        <p className="text-xs text-destructive">{workExperienceForm.errors.date_to}</p>
                                                    ) : null}
                                                </div>
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Responsibility</Label>
                                                <textarea
                                                    rows={4}
                                                    className="min-h-[88px] w-full resize-y rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-zinc-200 outline-none focus:ring-1 focus:ring-primary"
                                                    value={workExperienceForm.data.responsibility}
                                                    onChange={(e) => workExperienceForm.setData('responsibility', e.target.value)}
                                                />
                                                {workExperienceForm.errors.responsibility ? (
                                                    <p className="text-xs text-destructive">{workExperienceForm.errors.responsibility}</p>
                                                ) : null}
                                            </div>
                                        </div>
                                        <DialogFooter>
                                            <Button variant="outline" size="sm" type="button" onClick={() => setWorkExperienceDialogOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button
                                                size="sm"
                                                type="button"
                                                disabled={workExperienceForm.processing}
                                                onClick={() => {
                                                    workExperienceForm.clearErrors();
                                                    workExperienceForm.transform((data) => ({
                                                        company_name: data.company_name.trim(),
                                                        job_title: data.job_title.trim(),
                                                        date_from: data.date_from,
                                                        date_to: data.date_to === '' ? null : data.date_to,
                                                        responsibility:
                                                            data.responsibility.trim() === '' ? null : data.responsibility.trim(),
                                                    }));

                                                    const url = editingWorkExperience
                                                        ? updateWorkExperience.url({
                                                            employee: employee.id,
                                                            workExperience: editingWorkExperience.id,
                                                        })
                                                        : storeWorkExperience.url({ employee: employee.id });

                                                    if (editingWorkExperience) {
                                                        workExperienceForm.put(url, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setWorkExperienceDialogOpen(false);
                                                                workExperienceForm.reset();
                                                                setEditingWorkExperience(null);
                                                                toast.success('Work experience updated.');
                                                            },
                                                        });
                                                    } else {
                                                        workExperienceForm.post(url, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setWorkExperienceDialogOpen(false);
                                                                workExperienceForm.reset();
                                                                toast.success('Work experience added.');
                                                            },
                                                        });
                                                    }
                                                }}
                                            >
                                                {workExperienceForm.processing ? 'Saving…' : 'Save'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>

                                <AlertDialog
                                    open={!!deleteWorkExperienceId}
                                    onOpenChange={(openDialog) => {
                                        if (!openDialog) {
                                            setDeleteWorkExperienceId(null);
                                        }
                                    }}
                                >
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Remove work experience?</AlertDialogTitle>
                                            <AlertDialogDescription>This entry will be permanently removed.</AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                onClick={() => {
                                                    if (!deleteWorkExperienceId) {
                                                        return;
                                                    }

                                                    router.delete(
                                                        destroyWorkExperience.url({
                                                            employee: employee.id,
                                                            workExperience: deleteWorkExperienceId,
                                                        }), {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setDeleteWorkExperienceId(null);
                                                                toast.success('Work experience removed.');
                                                            },
                                                        });
                                                }}
                                            >
                                                Remove
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>

                                <WorkExperienceImportDialog
                                    open={workExperienceImportOpen}
                                    onOpenChange={setWorkExperienceImportOpen}
                                    employeeId={employee.id}
                                />
                            </TabsContent>

                            <TabsContent value="vaccination" className="mt-6">
                                <div className="rounded-2xl border border-white/10 bg-card/70 p-5 shadow-lg shadow-black/10 backdrop-blur-xl">
                                    <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                        <h3 className="text-sm font-semibold text-zinc-200">
                                            Vaccination
                                            <span className="ml-2 text-xs font-normal text-zinc-500">{vaccinations.length} total</span>
                                        </h3>
                                        {can.vaccination_manage ? (
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-8 gap-1.5 text-xs"
                                                    type="button"
                                                    onClick={() => setVaccinationImportOpen(true)}
                                                >
                                                    Import CSV
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    className="h-8 gap-1.5 text-xs"
                                                    type="button"
                                                    onClick={() => {
                                                        vaccinationForm.reset();
                                                        vaccinationForm.clearErrors();
                                                        setEditingVaccination(null);
                                                        setVaccinationDialogOpen(true);
                                                    }}
                                                >
                                                    + Add line
                                                </Button>
                                            </div>
                                        ) : null}
                                    </div>

                                    {vaccinations.length === 0 ? (
                                        <div className="py-10 text-center text-sm text-zinc-500">No vaccination records.</div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full min-w-[880px] text-left">
                                                <thead>
                                                    <tr className="border-b border-white/5 text-xs font-semibold text-zinc-500">
                                                        <th className="py-2 pr-4">Vaccination</th>
                                                        <th className="py-2 pr-4">Country</th>
                                                        <th className="py-2 pr-4">1st dose</th>
                                                        <th className="py-2 pr-4">2nd dose</th>
                                                        <th className="py-2 pr-4">Booster</th>
                                                        <th className="py-2 pr-4">Added</th>
                                                        {can.vaccination_manage ? <th className="py-2 pr-4 text-right" /> : null}
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-white/5">
                                                    {vaccinations.map((row) => (
                                                        <tr key={row.id} className="text-sm text-zinc-200">
                                                            <td className="max-w-[200px] truncate py-3 pr-4 font-medium" title={row.vaccination_name}>
                                                                {row.vaccination_name}
                                                            </td>
                                                            <td className="py-3 pr-4 text-xs text-zinc-400">{row.country_name ?? '—'}</td>
                                                            <td className="whitespace-nowrap py-3 pr-4 text-xs text-zinc-400">
                                                                {formatWorkExpDate(row.first_dose_date)}
                                                            </td>
                                                            <td className="whitespace-nowrap py-3 pr-4 text-xs text-zinc-400">
                                                                {formatWorkExpDate(row.second_dose_date)}
                                                            </td>
                                                            <td className="whitespace-nowrap py-3 pr-4 text-xs text-zinc-400">
                                                                {formatWorkExpDate(row.booster_dose_date)}
                                                            </td>
                                                            <td className="whitespace-nowrap py-3 pr-4 text-xs text-zinc-500">
                                                                {new Date(row.created_at).toLocaleString(undefined, {
                                                                    month: 'short',
                                                                    day: 'numeric',
                                                                    hour: 'numeric',
                                                                    minute: '2-digit',
                                                                })}
                                                            </td>
                                                            {can.vaccination_manage ? (
                                                                <td className="py-3 pr-0 text-right">
                                                                    <div className="flex items-center justify-end gap-2">
                                                                        <button
                                                                            type="button"
                                                                            className="text-xs text-zinc-400 transition-colors hover:text-zinc-200"
                                                                            onClick={() => {
                                                                                setEditingVaccination(row);
                                                                                vaccinationForm.setData({
                                                                                    vaccination_name: row.vaccination_name,
                                                                                    country_id: row.country_id ? String(row.country_id) : '',
                                                                                    first_dose_date: row.first_dose_date ?? '',
                                                                                    second_dose_date: row.second_dose_date ?? '',
                                                                                    booster_dose_date: row.booster_dose_date ?? '',
                                                                                });
                                                                                vaccinationForm.clearErrors();
                                                                                setVaccinationDialogOpen(true);
                                                                            }}
                                                                        >
                                                                            Edit
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            className="text-xs text-red-400/60 transition-colors hover:text-red-400"
                                                                            onClick={() => setDeleteVaccinationId(row.id)}
                                                                        >
                                                                            Delete
                                                                        </button>
                                                                    </div>
                                                                </td>
                                                            ) : null}
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>

                                <Dialog
                                    open={vaccinationDialogOpen}
                                    onOpenChange={(openDialog) => {
                                        setVaccinationDialogOpen(openDialog);

                                        if (!openDialog) {
                                            vaccinationForm.reset();
                                            vaccinationForm.clearErrors();
                                            setEditingVaccination(null);
                                        }
                                    }}
                                >
                                    <DialogContent className="sm:max-w-md">
                                        <DialogHeader>
                                            <DialogTitle>{editingVaccination ? 'Edit vaccination' : 'Add vaccination'}</DialogTitle>
                                        </DialogHeader>
                                        <div className="space-y-4 py-2">
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Vaccination</Label>
                                                <Input
                                                    className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                    value={vaccinationForm.data.vaccination_name}
                                                    onChange={(e) => vaccinationForm.setData('vaccination_name', e.target.value)}
                                                />
                                                {vaccinationForm.errors.vaccination_name ? (
                                                    <p className="text-xs text-destructive">{vaccinationForm.errors.vaccination_name}</p>
                                                ) : null}
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label className="text-xs">Country</Label>
                                                <select
                                                    value={vaccinationForm.data.country_id}
                                                    onChange={(e) => vaccinationForm.setData('country_id', e.target.value)}
                                                    className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm outline-none focus:ring-1 focus:ring-primary"
                                                >
                                                    <option value="">—</option>
                                                    {countries.map((c) => (
                                                        <option key={c.id} value={String(c.id)}>
                                                            {c.name}
                                                        </option>
                                                    ))}
                                                </select>
                                                {vaccinationForm.errors.country_id ? (
                                                    <p className="text-xs text-destructive">{vaccinationForm.errors.country_id}</p>
                                                ) : null}
                                            </div>
                                            <div className="grid gap-3 sm:grid-cols-3">
                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">1st dose</Label>
                                                    <Input
                                                        type="date"
                                                        className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                        value={vaccinationForm.data.first_dose_date}
                                                        onChange={(e) => vaccinationForm.setData('first_dose_date', e.target.value)}
                                                    />
                                                    {vaccinationForm.errors.first_dose_date ? (
                                                        <p className="text-xs text-destructive">{vaccinationForm.errors.first_dose_date}</p>
                                                    ) : null}
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">2nd dose</Label>
                                                    <Input
                                                        type="date"
                                                        className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                        value={vaccinationForm.data.second_dose_date}
                                                        onChange={(e) => vaccinationForm.setData('second_dose_date', e.target.value)}
                                                    />
                                                    {vaccinationForm.errors.second_dose_date ? (
                                                        <p className="text-xs text-destructive">{vaccinationForm.errors.second_dose_date}</p>
                                                    ) : null}
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label className="text-xs">Booster</Label>
                                                    <Input
                                                        type="date"
                                                        className="h-10 rounded-xl border-white/5 bg-white/5 text-sm"
                                                        value={vaccinationForm.data.booster_dose_date}
                                                        onChange={(e) => vaccinationForm.setData('booster_dose_date', e.target.value)}
                                                    />
                                                    {vaccinationForm.errors.booster_dose_date ? (
                                                        <p className="text-xs text-destructive">{vaccinationForm.errors.booster_dose_date}</p>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </div>
                                        <DialogFooter>
                                            <Button variant="outline" size="sm" type="button" onClick={() => setVaccinationDialogOpen(false)}>
                                                Cancel
                                            </Button>
                                            <Button
                                                size="sm"
                                                type="button"
                                                disabled={vaccinationForm.processing}
                                                onClick={() => {
                                                    vaccinationForm.clearErrors();
                                                    vaccinationForm.transform((data) => ({
                                                        vaccination_name: data.vaccination_name.trim(),
                                                        country_id: data.country_id === '' ? null : Number(data.country_id),
                                                        first_dose_date: data.first_dose_date === '' ? null : data.first_dose_date,
                                                        second_dose_date: data.second_dose_date === '' ? null : data.second_dose_date,
                                                        booster_dose_date: data.booster_dose_date === '' ? null : data.booster_dose_date,
                                                    }));

                                                    const url = editingVaccination
                                                        ? updateVaccination.url({
                                                            employee: employee.id,
                                                            vaccination: editingVaccination.id,
                                                        })
                                                        : storeVaccination.url({ employee: employee.id });

                                                    if (editingVaccination) {
                                                        vaccinationForm.put(url, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setVaccinationDialogOpen(false);
                                                                vaccinationForm.reset();
                                                                setEditingVaccination(null);
                                                                toast.success('Vaccination updated.');
                                                            },
                                                        });
                                                    } else {
                                                        vaccinationForm.post(url, {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setVaccinationDialogOpen(false);
                                                                vaccinationForm.reset();
                                                                toast.success('Vaccination added.');
                                                            },
                                                        });
                                                    }
                                                }}
                                            >
                                                {vaccinationForm.processing ? 'Saving…' : 'Save'}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>

                                <AlertDialog
                                    open={!!deleteVaccinationId}
                                    onOpenChange={(openDialog) => {
                                        if (!openDialog) {
                                            setDeleteVaccinationId(null);
                                        }
                                    }}
                                >
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Remove vaccination record?</AlertDialogTitle>
                                            <AlertDialogDescription>This entry will be permanently removed.</AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                onClick={() => {
                                                    if (!deleteVaccinationId) {
                                                        return;
                                                    }

                                                    router.delete(
                                                        destroyVaccination.url({
                                                            employee: employee.id,
                                                            vaccination: deleteVaccinationId,
                                                        }), {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setDeleteVaccinationId(null);
                                                                toast.success('Vaccination removed.');
                                                            },
                                                        });
                                                }}
                                            >
                                                Remove
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>

                                <VaccinationImportDialog
                                    open={vaccinationImportOpen}
                                    onOpenChange={setVaccinationImportOpen}
                                    employeeId={employee.id}
                                />
                            </TabsContent>

                            <EmployeeDocumentsTab
                                employee={{ id: employee.id, name: employee.name }}
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
