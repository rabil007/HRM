import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
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
import { actions, tabs as dsTabs } from '@/lib/design-system';
import { cn } from '@/lib/utils';
import { EmployeeDocumentsTab } from '@/pages/organization/_components/documents/employee-documents-tab';
import { EmployeeBankTab } from '@/pages/organization/_components/employee-bank-tab';
import { EmployeeContractTab } from '@/pages/organization/_components/employee-contract-tab';
import { EmployeeEducationTab } from '@/pages/organization/_components/employee-education-tab';
import { EmployeeHeaderCard } from '@/pages/organization/_components/employee-header-card';
import { EmployeeLanguagesTab } from '@/pages/organization/_components/employee-languages-tab';
import { EmployeePersonalTab } from '@/pages/organization/_components/employee-personal-tab';
import { EmployeeSeaServiceTab } from '@/pages/organization/_components/employee-sea-service-tab';
import { EmployeeTrainingTab } from '@/pages/organization/_components/employee-training-tab';
import { EmployeeVaccinationTab } from '@/pages/organization/_components/employee-vaccination-tab';
import { EmployeeWorkExperienceTab } from '@/pages/organization/_components/employee-work-experience-tab';
import {
    useEmployeeProfileForm,
} from '@/pages/organization/_hooks/use-employee-profile-form';
import type { UseEmployeeProfileFormResult } from '@/pages/organization/_hooks/use-employee-profile-form';
import type {
    EmployeePageProps,
    EmployeeTab,
} from '@/pages/organization/employee-page.types';

const EMPLOYEE_PAGE_TAB_HASH_KEYS: Partial<Record<string, EmployeeTab>> = {
    '#documents': 'documents',
    '#education': 'education',
    '#work-experience': 'work_experience',
    '#vaccination': 'vaccination',
    '#languages': 'languages',
    '#training': 'training',
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
    trainings,
    courses,
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
    visa_types,
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
    const recordsLoading = contracts === undefined;

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
                count:
                    contracts === undefined ? null : (contracts.length || null),
            },
            {
                id: 'bank' as const,
                label: 'Bank',
                count:
                    bank_accounts === undefined
                        ? null
                        : form.data.bank_id || form.data.iban
                          ? 1
                          : (bank_accounts.length || null),
            },
            {
                id: 'education' as const,
                label: 'Education',
                count:
                    education_qualifications === undefined
                        ? null
                        : (education_qualifications.length || null),
            },
            {
                id: 'work_experience' as const,
                label: 'Work experience',
                count:
                    work_experiences === undefined
                        ? null
                        : (work_experiences.length || null),
            },
            {
                id: 'vaccination' as const,
                label: 'Vaccination',
                count:
                    vaccinations === undefined
                        ? null
                        : (vaccinations.length || null),
            },
            {
                id: 'languages' as const,
                label: 'Languages',
                count:
                    languages === undefined ? null : (languages.length || null),
            },
            {
                id: 'training' as const,
                label: 'Training',
                count:
                    trainings === undefined ? null : (trainings.length || null),
            },
            {
                id: 'sea_service' as const,
                label: 'Sea Service',
                count:
                    sea_services === undefined
                        ? null
                        : (sea_services.length || null),
            },
            {
                id: 'documents' as const,
                label: 'Documents',
                count:
                    documents === undefined ? null : (documents.length || null),
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
                case 'training':
                    return employee_tabs.training;
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
        employee_tabs.training,
        contracts,
        documents,
        education_qualifications,
        bank_accounts,
        form.data.bank_id,
        form.data.iban,
        languages,
        trainings,
        sea_services,
        vaccinations,
        work_experiences,
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
                                        <AlertDialogTitle>
                                            Unsaved changes
                                        </AlertDialogTitle>
                                    </div>
                                    <AlertDialogDescription>
                                        You have unsaved changes. What would you
                                        like to do?
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter className="flex-col gap-2 sm:flex-row">
                                    <AlertDialogCancel className={actions.dialogSecondary}>
                                        Stay
                                    </AlertDialogCancel>
                                    <AlertDialogAction
                                        className={actions.dialogSecondary}
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
                                        className={actions.dialogPrimary}
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
                            departments={departments}
                            positions={positions}
                            ranks={ranks}
                            managers={managers}
                            countries={countries}
                            genders={genders}
                            religions={religions}
                            visa_types={visa_types}
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
                                    <TabsList className={cn(dsTabs.list, 'min-w-full flex-nowrap')}>
                                        {tabs.map((tab) => (
                                            <TabsTrigger
                                                key={tab.id}
                                                value={tab.id}
                                                className={cn(dsTabs.trigger, 'group')}
                                            >
                                                {tab.label}
                                                {tab.count !== null && (
                                                    <span className="ml-1.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-muted px-1 text-[10px] font-bold tabular-nums text-muted-foreground group-data-[state=active]:bg-primary/20 group-data-[state=active]:text-primary">
                                                        {tab.count}
                                                    </span>
                                                )}
                                            </TabsTrigger>
                                        ))}
                                    </TabsList>
                                </div>

                                <div>
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
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeContractTab
                                                employeeId={employee.id}
                                                contracts={contracts ?? []}
                                                canManage={can.contracts_manage}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.bank && activeTab === 'bank' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeBankTab
                                                employeeId={employee.id}
                                                bank_accounts={bank_accounts ?? []}
                                                banks={banks}
                                                canManage={can.bank_accounts_manage}
                                            />
                                        )
                                    ) : null}
                                    {activeTab === 'education' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeEducationTab
                                                employeeId={employee.id}
                                                education_qualifications={
                                                    education_qualifications ?? []
                                                }
                                                countries={countries}
                                                canManage={can.education_manage}
                                            />
                                        )
                                    ) : null}
                                    {activeTab === 'work_experience' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeWorkExperienceTab
                                                employeeId={employee.id}
                                                work_experiences={work_experiences ?? []}
                                                canManage={can.work_experience_manage}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.vaccination &&
                                    activeTab === 'vaccination' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeVaccinationTab
                                                employeeId={employee.id}
                                                vaccinations={vaccinations ?? []}
                                                countries={countries}
                                                canManage={can.vaccination_manage}
                                            />
                                        )
                                    ) : null}
                                    {activeTab === 'languages' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeLanguagesTab
                                                employeeId={employee.id}
                                                languages={languages ?? []}
                                                canManage={can.languages_manage}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.training && activeTab === 'training' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeTrainingTab
                                                employeeId={employee.id}
                                                trainings={trainings ?? []}
                                                courses={courses ?? []}
                                                countries={countries}
                                                canManage={can.training_manage}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.sea_service &&
                                    activeTab === 'sea_service' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeSeaServiceTab
                                                employeeId={employee.id}
                                                sea_services={sea_services ?? []}
                                                vessel_types={vessel_types ?? []}
                                                ranks={ranks}
                                                clients={clients ?? []}
                                                employeeRankId={employee.rank_id ?? null}
                                                canManage={can.sea_service_manage}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.documents && activeTab === 'documents' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeDocumentsTab
                                                employee={{
                                                    id: employee.id,
                                                    name: employee.name,
                                                }}
                                                documents={documents ?? []}
                                                document_types={document_types ?? []}
                                                can={{
                                                    documents_upload: can.documents_upload,
                                                    documents_delete: can.documents_delete,
                                                }}
                                            />
                                        )
                                    ) : null}
                                </div>
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
