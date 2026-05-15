import { Head, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
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
import { EmployeeBankTab } from '@/pages/organization/_components/employee-bank-tab';
import { EmployeeContractTab } from '@/pages/organization/_components/employee-contract-tab';
import { EmployeeDocumentsTab } from '@/pages/organization/_components/employee-documents-tab';
import { EmployeeEducationTab } from '@/pages/organization/_components/employee-education-tab';
import { EmployeeHeaderCard } from '@/pages/organization/_components/employee-header-card';
import { EmployeeLanguagesTab } from '@/pages/organization/_components/employee-languages-tab';
import { EmployeePersonalTab } from '@/pages/organization/_components/employee-personal-tab';
import { EmployeeSeaServiceTab } from '@/pages/organization/_components/employee-sea-service-tab';
import { EmployeeVaccinationTab } from '@/pages/organization/_components/employee-vaccination-tab';
import { EmployeeWorkExperienceTab } from '@/pages/organization/_components/employee-work-experience-tab';
import {
    useEmployeeProfileForm
    
} from '@/pages/organization/_hooks/use-employee-profile-form';
import type {UseEmployeeProfileFormResult} from '@/pages/organization/_hooks/use-employee-profile-form';
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
    employee,
    contract,
    documents,
    education_qualifications,
    work_experiences,
    vaccinations,
    languages,
    sea_services,
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
    vessels,
    clients,
    employee_tabs,
    recent_activity,
}: EmployeePageProps) {
    const { auth } = usePage().props as unknown as {
        auth?: { permissions?: string[] };
    };

    const canUpdate = (auth?.permissions ?? []).includes('employees.update');

    void branches;
    void departments;
    void positions;
    void managers;
    void users;
    void recent_activity;

    const {
        form,
        isDirty,
        displayName,
        activeField,
        setActiveField,
        beginEdit,
        requiredDot,
        saveChanges,
        discardChanges,
    }: UseEmployeeProfileFormResult = useEmployeeProfileForm(
        employee,
        contract,
        canUpdate,
    );

    const [tabValue, setTabValue] = useState<EmployeeTab>(
        initialEmployeeTabFromLocation,
    );

    const [pendingTab, setPendingTab] = useState<EmployeeTab | null>(null);
    const [unsavedDialogOpen, setUnsavedDialogOpen] = useState(false);

    const tabs = useMemo(() => {
        const list = [
            { id: 'personal' as const, label: 'Personal', count: null },
            { id: 'contract' as const, label: 'Contract', count: null },
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
        documents.length,
        education_qualifications.length,
        form.data.bank_id,
        form.data.iban,
        languages.length,
        sea_services.length,
        vaccinations.length,
        work_experiences.length,
    ]);

    useEffect(() => {
        if (tabs.some((t) => t.id === tabValue)) {
            return;
        }

        setTabValue(tabs[0]?.id ?? 'personal');
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

            setPendingTab(next);
            setUnsavedDialogOpen(true);
        },
        [canUpdate, isDirty],
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

                                {employee_tabs.personal ? (
                                    <EmployeePersonalTab
                                        employee={employee}
                                        countries={countries}
                                        form={form}
                                        activeField={activeField}
                                        setActiveField={setActiveField}
                                        beginEdit={beginEdit}
                                    />
                                ) : null}
                                {employee_tabs.contract ? (
                                    <EmployeeContractTab
                                        contract={contract}
                                        form={form}
                                        activeField={activeField}
                                        setActiveField={setActiveField}
                                        beginEdit={beginEdit}
                                        requiredDot={requiredDot}
                                    />
                                ) : null}
                                {employee_tabs.bank ? (
                                    <EmployeeBankTab
                                        employee={employee}
                                        banks={banks}
                                        form={form}
                                        activeField={activeField}
                                        setActiveField={setActiveField}
                                        beginEdit={beginEdit}
                                    />
                                ) : null}
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
                                {employee_tabs.vaccination ? (
                                    <EmployeeVaccinationTab
                                        employeeId={employee.id}
                                        vaccinations={vaccinations}
                                        countries={countries}
                                        canManage={can.vaccination_manage}
                                    />
                                ) : null}
                                <EmployeeLanguagesTab
                                    employeeId={employee.id}
                                    languages={languages}
                                    canManage={can.languages_manage}
                                />
                                {employee_tabs.sea_service ? (
                                    <EmployeeSeaServiceTab
                                        employeeId={employee.id}
                                        sea_services={sea_services}
                                        vessels={vessels}
                                        ranks={ranks}
                                        clients={clients}
                                        employeeRankId={employee.rank_id ?? null}
                                        canManage={can.sea_service_manage}
                                    />
                                ) : null}
                                {employee_tabs.documents ? (
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
