import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from 'react';
import { show } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
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
import { EmployeeTabSkeleton } from '@/features/organization/employees/profile/components/employee-tab-skeleton';
import { EmployeeHeaderCard } from '@/pages/organization/_components/employee-header-card';
import {
    useEmployeeProfileForm,
} from '@/pages/organization/_hooks/use-employee-profile-form';
import type { UseEmployeeProfileFormResult } from '@/pages/organization/_hooks/use-employee-profile-form';
import type {
    EmployeePageProps,
    EmployeeTab,
} from '@/pages/organization/employee-page.types';

const EmployeePersonalTab = lazy(() =>
    import('@/pages/organization/_components/employee-personal-tab').then((module) => ({
        default: module.EmployeePersonalTab,
    })),
);

const EmployeeContractTab = lazy(() =>
    import('@/pages/organization/_components/employee-contract-tab').then((module) => ({
        default: module.EmployeeContractTab,
    })),
);

const EmployeeBankTab = lazy(() =>
    import('@/pages/organization/_components/employee-bank-tab').then((module) => ({
        default: module.EmployeeBankTab,
    })),
);

const EmployeeEducationTab = lazy(() =>
    import('@/pages/organization/_components/employee-education-tab').then((module) => ({
        default: module.EmployeeEducationTab,
    })),
);

const EmployeeWorkExperienceTab = lazy(() =>
    import('@/pages/organization/_components/employee-work-experience-tab').then((module) => ({
        default: module.EmployeeWorkExperienceTab,
    })),
);

const EmployeeVaccinationTab = lazy(() =>
    import('@/pages/organization/_components/employee-vaccination-tab').then((module) => ({
        default: module.EmployeeVaccinationTab,
    })),
);

const EmployeeLanguagesTab = lazy(() =>
    import('@/pages/organization/_components/employee-languages-tab').then((module) => ({
        default: module.EmployeeLanguagesTab,
    })),
);

const EmployeeSeaServiceTab = lazy(() =>
    import('@/pages/organization/_components/employee-sea-service-tab').then((module) => ({
        default: module.EmployeeSeaServiceTab,
    })),
);

const EmployeeDocumentsTab = lazy(() =>
    import('@/pages/organization/_components/documents/employee-documents-tab').then(
        (module) => ({
            default: module.EmployeeDocumentsTab,
        }),
    ),
);

const EMPLOYEE_PAGE_TAB_HASH_KEYS: Partial<Record<string, EmployeeTab>> = {
    '#documents': 'documents',
    '#education': 'education',
    '#work-experience': 'work_experience',
    '#vaccination': 'vaccination',
    '#languages': 'languages',
    '#sea-service': 'sea_service',
};

const EMPLOYEE_PAGE_LEGACY_HASH_KEYS = new Set(
    Object.keys(EMPLOYEE_PAGE_TAB_HASH_KEYS),
);

function initialEmployeeTabFromLocation(): EmployeeTab {
    if (typeof window === 'undefined') {
        return 'personal';
    }

    return EMPLOYEE_PAGE_TAB_HASH_KEYS[window.location.hash] ?? 'personal';
}

export default function EmployeeDetails({
    employee_navigation,
    employee,
    contracts,
    documents,
    education_qualifications,
    work_experiences,
    vaccinations,
    languages,
    bank_accounts,
    sea_services,
    document_types,
    can,
    branches,
    departments,
    positions,
    managers,
    countries,
    religions,
    genders,
    banks,
    ranks,
    vessel_types,
    clients,
    employee_tabs,
}: EmployeePageProps) {
    const { auth } = usePage().props as unknown as {
        auth?: { permissions?: string[] };
    };

    const canUpdate = (auth?.permissions ?? []).includes('employees.update');

    void branches;
    void departments;
    void positions;
    void managers;

    const {
        form,
        isDirty,
        displayName,
        activeField,
        setActiveField,
        beginEdit,
        requiredDot,
        saveChanges,
        uploadPhoto,
        isUploadingPhoto,
        discardChanges,
    }: UseEmployeeProfileFormResult = useEmployeeProfileForm(
        employee,
        canUpdate,
    );

    const [tabValue, setTabValue] = useState<EmployeeTab>(
        initialEmployeeTabFromLocation,
    );

    const [pendingTab, setPendingTab] = useState<EmployeeTab | null>(null);
    const [pendingEmployeeId, setPendingEmployeeId] = useState<number | null>(null);
    const [unsavedDialogOpen, setUnsavedDialogOpen] = useState(false);

    const visitEmployeeProfile = useCallback(
        (employeeId: number) => {
            const listQuery = employee_navigation?.list_query ?? {};
            const baseUrl = show.url({ employee: employeeId }, { query: listQuery });
            const hash =
                typeof window !== 'undefined' ? window.location.hash : '';

            router.visit(hash ? `${baseUrl}${hash}` : baseUrl, {
                preserveScroll: true,
            });
        },
        [employee_navigation?.list_query],
    );

    const tabs = useMemo(() => {
        const list = [
            { id: 'personal' as const, label: 'Personal', count: null },
            {
                id: 'contract' as const,
                label: 'Contract',
                count: contracts.length || null,
            },
            {
                id: 'bank' as const,
                label: 'Bank',
                count: form.data.bank_id || form.data.iban ? 1 : null,
            },
            {
                id: 'education' as const,
                label: 'Education',
                count: education_qualifications.length || null,
            },
            {
                id: 'work_experience' as const,
                label: 'Work experience',
                count: work_experiences.length || null,
            },
            {
                id: 'vaccination' as const,
                label: 'Vaccination',
                count: vaccinations.length || null,
            },
            {
                id: 'languages' as const,
                label: 'Languages',
                count: languages.length || null,
            },
            {
                id: 'sea_service' as const,
                label: 'Sea Service',
                count: sea_services.length || null,
            },
            {
                id: 'documents' as const,
                label: 'Documents',
                count: documents.length || null,
            },
        ] satisfies Array<{
            id: EmployeeTab;
            label: string;
            count: number | null;
        }>;

        return list.filter((tab) => {
            switch (tab.id) {
                case 'personal':
                    return employee_tabs.personal;
                case 'contract':
                    return employee_tabs.contract;
                case 'bank':
                    return employee_tabs.bank;
                case 'documents':
                    return employee_tabs.documents;
                case 'sea_service':
                    return employee_tabs.sea_service;
                case 'vaccination':
                    return employee_tabs.vaccination;
                default:
                    return true;
            }
        });
    }, [
        employee_tabs.bank,
        employee_tabs.contract,
        employee_tabs.documents,
        employee_tabs.personal,
        employee_tabs.sea_service,
        employee_tabs.vaccination,
        contracts.length,
        documents.length,
        education_qualifications.length,
        form.data.bank_id,
        form.data.iban,
        languages.length,
        sea_services.length,
        vaccinations.length,
        work_experiences.length,
    ]);

    const activeTab = useMemo((): EmployeeTab => {
        if (tabs.some((t) => t.id === tabValue)) {
            return tabValue;
        }

        return tabs[0]?.id ?? 'personal';
    }, [tabs, tabValue]);

    useEffect(() => {
        if (EMPLOYEE_PAGE_LEGACY_HASH_KEYS.has(window.location.hash)) {
            window.history.replaceState(null, '', window.location.pathname);
        }
    }, []);

    const handleTabChange = useCallback(
        (next: EmployeeTab) => {
            if (!canUpdate || !isDirty) {
                setTabValue(next);

                return;
            }

            setPendingEmployeeId(null);
            setPendingTab(next);
            setUnsavedDialogOpen(true);
        },
        [canUpdate, isDirty],
    );

    const handleNavigateEmployee = useCallback(
        (employeeId: number) => {
            if (employeeId === employee.id) {
                return;
            }

            if (!canUpdate || !isDirty) {
                visitEmployeeProfile(employeeId);

                return;
            }

            setPendingTab(null);
            setPendingEmployeeId(employeeId);
            setUnsavedDialogOpen(true);
        },
        [canUpdate, employee.id, isDirty, visitEmployeeProfile],
    );

    return (
        <>
            <Head title={`Employee • ${displayName}`} />
            <Main className="min-h-screen bg-[radial-gradient(circle_at_top_right,rgba(99,102,241,0.10),transparent_28%),radial-gradient(circle_at_bottom_left,rgba(16,185,129,0.08),transparent_26%)] p-0">
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
                            onOpenChange={(open) => {
                                setUnsavedDialogOpen(open);

                                if (!open) {
                                    setPendingTab(null);
                                    setPendingEmployeeId(null);
                                }
                            }}
                        >
                            <AlertDialogContent className="sm:max-w-sm">
                                <AlertDialogHeader>
                                    <div className="mb-1 flex items-center gap-3">
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-amber-400">
                                            <AlertTriangle className="size-4" />
                                        </span>
                                        <AlertDialogTitle className="text-zinc-100">
                                            Unsaved changes
                                        </AlertDialogTitle>
                                    </div>
                                    <AlertDialogDescription className="text-zinc-400">
                                        You have unsaved changes. What would you
                                        like to do?
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter className="flex-col gap-2 sm:flex-row">
                                    <AlertDialogCancel className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100">
                                        Stay
                                    </AlertDialogCancel>
                                    <AlertDialogAction
                                        className="border-white/10 bg-white/5 text-zinc-300 hover:bg-white/10 hover:text-zinc-100"
                                        onClick={() => {
                                            const nextEmployeeId = pendingEmployeeId;

                                            discardChanges();

                                            if (pendingTab) {
                                                setTabValue(pendingTab);
                                            }

                                            if (nextEmployeeId !== null) {
                                                visitEmployeeProfile(nextEmployeeId);
                                            }

                                            setPendingTab(null);
                                            setPendingEmployeeId(null);
                                            setUnsavedDialogOpen(false);
                                        }}
                                    >
                                        Discard
                                    </AlertDialogAction>
                                    <AlertDialogAction
                                        className="bg-indigo-600 text-white hover:bg-indigo-500"
                                        onClick={() => {
                                            const nextEmployeeId = pendingEmployeeId;

                                            saveChanges(() => {
                                                if (pendingTab) {
                                                    setTabValue(pendingTab);
                                                }

                                                if (nextEmployeeId !== null) {
                                                    visitEmployeeProfile(nextEmployeeId);
                                                }

                                                setPendingTab(null);
                                                setPendingEmployeeId(null);
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
                            employeeNavigation={employee_navigation}
                            onNavigateEmployee={handleNavigateEmployee}
                            branches={branches}
                            departments={departments}
                            positions={positions}
                            ranks={ranks}
                            managers={managers}
                            countries={countries}
                            genders={genders}
                            religions={religions}
                            form={form}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            requiredDot={requiredDot}
                            onPhotoSelect={uploadPhoto}
                            isUploadingPhoto={isUploadingPhoto}
                            templateProfileFields={employee_tabs.profile_fields}
                        />

                        <div id="employee-tabs" className="space-y-4">
                            <Tabs
                                value={activeTab}
                                onValueChange={(v) =>
                                    handleTabChange(v as EmployeeTab)
                                }
                                className="w-full"
                            >
                                <div className="hide-scrollbar overflow-x-auto">
                                    <TabsList className="inline-flex h-auto min-w-full flex-nowrap items-center gap-1 rounded-2xl border border-white/[0.08] bg-white/[0.03] p-1.5 shadow-inner shadow-black/20 backdrop-blur-xl">
                                        {tabs.map((tab) => (
                                            <TabsTrigger
                                                key={tab.id}
                                                value={tab.id}
                                                className="group relative shrink-0 cursor-pointer rounded-xl border border-transparent bg-transparent px-4 py-2 text-xs font-semibold tracking-wide whitespace-nowrap text-zinc-500 transition-all duration-200 hover:border-white/10 hover:bg-white/[0.06] hover:text-zinc-300 data-[state=active]:border-indigo-500/20 data-[state=active]:bg-indigo-500/10 data-[state=active]:text-indigo-300 data-[state=active]:shadow-md data-[state=active]:shadow-indigo-950/40"
                                            >
                                                {tab.label}
                                                {tab.count !== null && (
                                                    <span className="ml-1.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-white/[0.08] px-1 text-[10px] font-bold tabular-nums text-zinc-500 group-data-[state=active]:bg-indigo-500/20 group-data-[state=active]:text-indigo-300">
                                                        {tab.count}
                                                    </span>
                                                )}
                                            </TabsTrigger>
                                        ))}
                                    </TabsList>
                                </div>

                                <Suspense fallback={<EmployeeTabSkeleton />}>
                                    {employee_tabs.personal && activeTab === 'personal' ? (
                                        <EmployeePersonalTab
                                            employee={employee}
                                            countries={countries}
                                            form={form}
                                            activeField={activeField}
                                            setActiveField={setActiveField}
                                            beginEdit={beginEdit}
                                        />
                                    ) : null}
                                    {employee_tabs.contract && activeTab === 'contract' ? (
                                        <EmployeeContractTab
                                            employeeId={employee.id}
                                            contracts={contracts}
                                            canManage={can.contracts_manage}
                                        />
                                    ) : null}
                                    {employee_tabs.bank && activeTab === 'bank' ? (
                                        <EmployeeBankTab
                                            employeeId={employee.id}
                                            bank_accounts={bank_accounts}
                                            banks={banks}
                                            canManage={can.bank_accounts_manage}
                                        />
                                    ) : null}
                                    {activeTab === 'education' ? (
                                        <EmployeeEducationTab
                                            employeeId={employee.id}
                                            education_qualifications={
                                                education_qualifications
                                            }
                                            countries={countries}
                                            canManage={can.education_manage}
                                        />
                                    ) : null}
                                    {activeTab === 'work_experience' ? (
                                        <EmployeeWorkExperienceTab
                                            employeeId={employee.id}
                                            work_experiences={work_experiences}
                                            canManage={can.work_experience_manage}
                                        />
                                    ) : null}
                                    {employee_tabs.vaccination &&
                                    activeTab === 'vaccination' ? (
                                        <EmployeeVaccinationTab
                                            employeeId={employee.id}
                                            vaccinations={vaccinations}
                                            countries={countries}
                                            canManage={can.vaccination_manage}
                                        />
                                    ) : null}
                                    {activeTab === 'languages' ? (
                                        <EmployeeLanguagesTab
                                            employeeId={employee.id}
                                            languages={languages}
                                            canManage={can.languages_manage}
                                        />
                                    ) : null}
                                    {employee_tabs.sea_service &&
                                    activeTab === 'sea_service' ? (
                                        <EmployeeSeaServiceTab
                                            employeeId={employee.id}
                                            sea_services={sea_services}
                                            vessel_types={vessel_types}
                                            ranks={ranks}
                                            clients={clients}
                                            employeeRankId={employee.rank_id ?? null}
                                            canManage={can.sea_service_manage}
                                        />
                                    ) : null}
                                    {employee_tabs.documents && activeTab === 'documents' ? (
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
                                    ) : null}
                                </Suspense>
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
