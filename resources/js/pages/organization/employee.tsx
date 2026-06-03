import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { show } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import printEmployeeCv from '@/actions/App/Http/Controllers/Organization/EmployeeCvPrintController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import { EmployeeTabSkeleton } from '@/features/organization/employees/profile/components/employee-tab-skeleton';
import { EmployeeProfileShell } from '@/features/organization/employees/profile/employee-profile-shell';
import { buildEmployeeProfileTabs } from '@/features/organization/employees/profile/employee-profile-tabs';
import {
    useEnsureEmployee
    
} from '@/features/organization/employees/profile/use-ensure-employee';
import type {EnsuredEmployee} from '@/features/organization/employees/profile/use-ensure-employee';
import { actions } from '@/lib/design-system';
import { CreateEmployeeUserDialog } from '@/pages/organization/_components/create-employee-user-dialog';
import { EmployeeDocumentsTab } from '@/pages/organization/_components/documents/employee-documents-tab';
import { EmployeeBankTab } from '@/pages/organization/_components/employee-bank-tab';
import { EmployeeContractTab } from '@/pages/organization/_components/employee-contract-tab';
import { EmployeeEducationTab } from '@/pages/organization/_components/employee-education-tab';
import { EmployeeHeaderCard } from '@/pages/organization/_components/employee-header-card';
import { EmployeeLanguagesTab } from '@/pages/organization/_components/employee-languages-tab';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
import { EmployeePersonalTab } from '@/pages/organization/_components/employee-personal-tab';
import { EmployeeProfileActionBar } from '@/pages/organization/_components/employee-profile-action-bar';
import { EmployeeSeaServiceTab } from '@/pages/organization/_components/employee-sea-service-tab';
import { EmployeeTrainingTab } from '@/pages/organization/_components/employee-training-tab';
import { EmployeeVaccinationTab } from '@/pages/organization/_components/employee-vaccination-tab';
import { EmployeeWorkExperienceTab } from '@/pages/organization/_components/employee-work-experience-tab';
import {
    useEmployeeProfileForm,
} from '@/pages/organization/_hooks/use-employee-profile-form';
import type { UseEmployeeProfileFormResult } from '@/pages/organization/_hooks/use-employee-profile-form';
import { resolveTemplateTableFields } from '@/pages/organization/_lib/resolve-template-table-fields';
import type {
    EmployeeDetails,
    EmployeePageProps,
    EmployeeTab,
} from '@/pages/organization/employee-page.types';
import { employee as employeeDocumentsBrowse } from '@/routes/organization/documents';

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

export default function EmployeeDetails(props: EmployeePageProps) {
    const pageKey =
        props.mode === 'create'
            ? `create-${props.employee.id ?? 'new'}-${props.selected_profile_template_id ?? 'none'}`
            : `${props.employee.id}-${props.employee.updated_at}`;

    return <EmployeeDetailsPage key={pageKey} {...props} />;
}

function EmployeeDetailsPage({
    mode = 'edit',
    employee_navigation = null,
    resolved_template,
    profile_templates = [],
    selected_profile_template_id = null,
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
    roles,
    branches,
    departments,
    positions,
    managers,
    countries,
    religions,
    genders,
    visa_types,
    company_visa_types,
    approval_locations,
    sssa_options,
    banks,
    ranks,
    vessel_types,
    clients,
    employee_tabs,
}: EmployeePageProps) {
    const isCreateMode = mode === 'create';

    const { auth } = usePage().props as unknown as {
        auth?: { permissions?: string[] };
    };

    const [localEmployee, setLocalEmployee] = useState(employee);

    const linkedUser = employee.user ?? localEmployee.user;

    const formDraftRef = useRef({
        name: String(employee.name ?? ''),
        employee_no: String(employee.employee_no ?? ''),
    });
    const [selectedTemplateId, setSelectedTemplateId] = useState<number | null>(
        selected_profile_template_id,
    );
    const [tabValue, setTabValue] = useState<EmployeeTab>(() => {
        if (typeof window === 'undefined') {
            return 'personal';
        }

        if (isCreateMode && EMPLOYEE_PAGE_TAB_HASH_KEYS[window.location.hash]) {
            window.history.replaceState(
                null,
                '',
                window.location.pathname + window.location.search,
            );

            return 'personal';
        }

        return EMPLOYEE_PAGE_TAB_HASH_KEYS[window.location.hash] ?? 'personal';
    });
    const [pendingTab, setPendingTab] = useState<EmployeeTab | null>(null);
    const [pendingEmployeeId, setPendingEmployeeId] = useState<number | null>(null);
    const [unsavedDialogOpen, setUnsavedDialogOpen] = useState(false);
    const [createUserOpen, setCreateUserOpen] = useState(false);

    const handleEnsured = useCallback((ensured: EnsuredEmployee) => {
        setLocalEmployee((current) => ({
            ...current,
            id: ensured.id,
            name: ensured.name,
            employee_no: ensured.employee_no,
        }));
    }, []);

    const canUpdate = isCreateMode
        ? true
        : (auth?.permissions ?? []).includes('employees.update');
    const recordsLoading = !isCreateMode && contracts === undefined;

    void branches;
    void departments;
    void positions;
    void managers;

    const ensureEmployee = useEnsureEmployee({
        employeeId: localEmployee.id,
        getDraftName: () => formDraftRef.current.name,
        selectedProfileTemplateId: selectedTemplateId,
        onEnsured: handleEnsured,
    });

    const {
        form,
        isDirty,
        displayName,
        activeField,
        setActiveField,
        beginEdit,
        requiredDot,
        isMissingRequired,
        missingRequiredFields,
        focusMissingField,
        saveChanges,
        stagePhoto,
        removePhoto,
        discardChanges,
    }: UseEmployeeProfileFormResult = useEmployeeProfileForm(
        localEmployee as EmployeeDetails,
        canUpdate,
        {
            ensureEmployee: isCreateMode ? ensureEmployee : undefined,
            templateRequiredFields:
                employee_tabs.template_fields?.employees ??
                resolved_template?.fields?.employees,
        },
    );

    useEffect(() => {
        formDraftRef.current = {
            name: String(form.data.name ?? ''),
            employee_no: String(form.data.employee_no ?? ''),
        };
    }, [form.data.name, form.data.employee_no]);

    const canViewLinkedUser = (auth?.permissions ?? []).includes('users.view');
    const canCreateUser =
        !isCreateMode &&
        (can?.create_user ?? false) &&
        linkedUser === null;

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

    const effectiveEmployeeId = localEmployee.id ?? null;

    const tabs = useMemo(
        () =>
            buildEmployeeProfileTabs({
                employee_tabs,
                counts: {
                    contracts:
                        contracts === undefined
                            ? null
                            : contracts.length || null,
                    bank_accounts:
                        bank_accounts === undefined
                            ? null
                            : localEmployee.bank_id || localEmployee.iban
                              ? 1
                              : bank_accounts.length || null,
                    education_qualifications:
                        education_qualifications === undefined
                            ? null
                            : education_qualifications.length || null,
                    work_experiences:
                        work_experiences === undefined
                            ? null
                            : work_experiences.length || null,
                    vaccinations:
                        vaccinations === undefined
                            ? null
                            : vaccinations.length || null,
                    languages:
                        languages === undefined ? null : languages.length || null,
                    trainings:
                        trainings === undefined ? null : trainings.length || null,
                    sea_services:
                        sea_services === undefined
                            ? null
                            : sea_services.length || null,
                    documents:
                        documents === undefined ? null : documents.length || null,
                },
            }),
        [
            employee_tabs,
            contracts,
            documents,
            education_qualifications,
            bank_accounts,
            localEmployee.bank_id,
            localEmployee.iban,
            languages,
            trainings,
            sea_services,
            vaccinations,
            work_experiences,
        ],
    );

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
            if (employeeId === localEmployee.id) {
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
        [canUpdate, localEmployee.id, isDirty, visitEmployeeProfile],
    );

    const changeProfileTemplate = (templateId: string) => {
        const nextId = templateId === '' ? null : Number.parseInt(templateId, 10);
        setSelectedTemplateId(Number.isNaN(nextId as number) ? null : nextId);

        const search = new URLSearchParams();

        if (effectiveEmployeeId) {
            search.set('employee_id', String(effectiveEmployeeId));
        }

        if (nextId) {
            search.set('profile_template_id', String(nextId));
        }

        router.visit(
            `/organization/employees/create${search.toString() ? `?${search}` : ''}`,
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head
                title={
                    isCreateMode
                        ? `New employee • ${displayName}`
                        : `Employee • ${displayName}`
                }
            />
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
                        <EmployeeMissingRequiredFieldsAlert
                            missingFields={missingRequiredFields}
                            onFocusField={focusMissingField}
                        />
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

                        {isCreateMode ? (
                            <div className="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card/40 p-4 md:flex-row md:items-end md:justify-between">
                                <div className="space-y-1">
                                    <h1 className="text-lg font-semibold text-foreground">
                                        New employee
                                    </h1>
                                    <p className="text-sm text-muted-foreground">
                                        Enter a name, then add details in any tab. The
                                        employee record is created when you first save.
                                    </p>
                                </div>
                                <div className="flex w-full flex-col gap-3 md:w-auto md:min-w-[280px]">
                                    <div className="space-y-1.5">
                                        <label className="text-xs font-medium text-muted-foreground">
                                            Profile template
                                        </label>
                                        <AppSelect
                                            value={
                                                selectedTemplateId
                                                    ? String(selectedTemplateId)
                                                    : ''
                                            }
                                            onValueChange={changeProfileTemplate}
                                            placeholder="All tabs and fields (default)"
                                        >
                                            <AppSelectItem value="">
                                                Default (show all)
                                            </AppSelectItem>
                                            {profile_templates.map((template) => (
                                                <AppSelectItem
                                                    key={template.id}
                                                    value={String(template.id)}
                                                >
                                                    {template.name}
                                                </AppSelectItem>
                                            ))}
                                        </AppSelect>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <EmployeeProfileActionBar
                                printCvUrl={printEmployeeCv.url(
                                    { employee: localEmployee.id as number },
                                    { query: { format: 'pdf', inline: 1 } },
                                )}
                                employeeNavigation={employee_navigation}
                                onNavigateEmployee={handleNavigateEmployee}
                                showDocumentsButton={
                                    employee_tabs.documents && (can?.documents_view ?? false)
                                }
                                documentCount={
                                    documents === undefined ? null : documents.length
                                }
                                documentsBrowseUrl={employeeDocumentsBrowse.url({
                                    employee: localEmployee.id as number,
                                })}
                                showCreateUserButton={canCreateUser}
                                onCreateUser={() => setCreateUserOpen(true)}
                                linkedUser={linkedUser}
                                showLinkedUserButton={
                                    !isCreateMode &&
                                    canViewLinkedUser &&
                                    linkedUser !== null
                                }
                            />
                        )}

                        <CreateEmployeeUserDialog
                            open={createUserOpen}
                            onOpenChange={setCreateUserOpen}
                            employee={employee}
                            roles={roles}
                            onSuccess={() => {
                                router.reload({ only: ['employee'] });
                            }}
                        />

                        <EmployeeHeaderCard
                            canUpdate={canUpdate}
                            canAssignProfileTemplate={can?.assign_profile_template ?? false}
                            profileTemplates={profile_templates}
                            employee={localEmployee}
                            linkedUser={linkedUser}
                            departments={departments}
                            positions={positions}
                            ranks={ranks}
                            managers={managers}
                            countries={countries}
                            genders={genders}
                            religions={religions}
                            visa_types={visa_types}
                            company_visa_types={company_visa_types}
                            form={form}
                            activeField={activeField}
                            setActiveField={setActiveField}
                            beginEdit={beginEdit}
                            requiredDot={requiredDot}
                            onPhotoSelect={stagePhoto}
                            onPhotoRemove={removePhoto}
                            templateProfileFields={employee_tabs.profile_fields}
                            isMissingRequired={isMissingRequired}
                        />

                        <EmployeeProfileShell
                            activeTab={activeTab}
                            onTabChange={handleTabChange}
                            tabs={tabs}
                        >
                                    {employee_tabs.personal && activeTab === 'personal' ? (
                                        <EmployeePersonalTab
                                            employee={localEmployee}
                                            countries={countries}
                                            approvalLocations={approval_locations}
                                            sssaOptions={sssa_options}
                                            canUpdate={canUpdate}
                                            form={form}
                                            activeField={activeField}
                                            setActiveField={setActiveField}
                                            beginEdit={beginEdit}
                                            templateProfileFields={
                                                employee_tabs.profile_fields
                                            }
                                            isMissingRequired={isMissingRequired}
                                        />
                                    ) : null}
                                    {employee_tabs.contract && activeTab === 'contract' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeContractTab
                                                employeeId={effectiveEmployeeId}
                                                contracts={contracts ?? []}
                                                canManage={can?.contracts_manage ?? false}
                                                ensureEmployee={
                                                    isCreateMode
                                                        ? ensureEmployee
                                                        : undefined
                                                }
                                                templateContractFields={
                                                    resolveTemplateTableFields(
                                                        employee_tabs.template_fields,
                                                        resolved_template?.fields,
                                                        'employee_contracts',
                                                    )
                                                }
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.bank && activeTab === 'bank' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeBankTab
                                                employeeId={effectiveEmployeeId}
                                                bank_accounts={bank_accounts ?? []}
                                                banks={banks}
                                                canManage={can?.bank_accounts_manage ?? false}
                                                ensureEmployee={
                                                    isCreateMode
                                                        ? ensureEmployee
                                                        : undefined
                                                }
                                                templateFields={resolveTemplateTableFields(
                                                    employee_tabs.template_fields,
                                                    resolved_template?.fields,
                                                    'employee_bank_accounts',
                                                )}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.education !== false &&
                                    activeTab === 'education' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeEducationTab
                                                employeeId={effectiveEmployeeId}
                                                education_qualifications={
                                                    education_qualifications ?? []
                                                }
                                                countries={countries}
                                                canManage={can?.education_manage ?? false}
                                                ensureEmployee={
                                                    isCreateMode
                                                        ? ensureEmployee
                                                        : undefined
                                                }
                                                templateFields={resolveTemplateTableFields(
                                                    employee_tabs.template_fields,
                                                    resolved_template?.fields,
                                                    'employee_education_qualifications',
                                                )}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.work_experience !== false &&
                                    activeTab === 'work_experience' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeWorkExperienceTab
                                                employeeId={effectiveEmployeeId}
                                                work_experiences={work_experiences ?? []}
                                                canManage={
                                                    can?.work_experience_manage ?? false
                                                }
                                                ensureEmployee={
                                                    isCreateMode
                                                        ? ensureEmployee
                                                        : undefined
                                                }
                                                templateFields={resolveTemplateTableFields(
                                                    employee_tabs.template_fields,
                                                    resolved_template?.fields,
                                                    'employee_work_experiences',
                                                )}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.vaccination &&
                                    activeTab === 'vaccination' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeVaccinationTab
                                                employeeId={effectiveEmployeeId}
                                                vaccinations={vaccinations ?? []}
                                                countries={countries}
                                                canManage={can?.vaccination_manage ?? false}
                                                ensureEmployee={
                                                    isCreateMode
                                                        ? ensureEmployee
                                                        : undefined
                                                }
                                                templateFields={resolveTemplateTableFields(
                                                    employee_tabs.template_fields,
                                                    resolved_template?.fields,
                                                    'employee_vaccinations',
                                                )}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.languages !== false &&
                                    activeTab === 'languages' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeLanguagesTab
                                                employeeId={effectiveEmployeeId}
                                                languages={languages ?? []}
                                                canManage={can?.languages_manage ?? false}
                                                ensureEmployee={
                                                    isCreateMode
                                                        ? ensureEmployee
                                                        : undefined
                                                }
                                                templateFields={resolveTemplateTableFields(
                                                    employee_tabs.template_fields,
                                                    resolved_template?.fields,
                                                    'employee_languages',
                                                )}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.training && activeTab === 'training' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeTrainingTab
                                                employeeId={effectiveEmployeeId}
                                                employeeName={employee.name}
                                                trainings={trainings ?? []}
                                                courses={courses ?? []}
                                                countries={countries}
                                                canManage={can?.training_manage ?? false}
                                                ensureEmployee={
                                                    isCreateMode
                                                        ? ensureEmployee
                                                        : undefined
                                                }
                                                templateFields={resolveTemplateTableFields(
                                                    employee_tabs.template_fields,
                                                    resolved_template?.fields,
                                                    'employee_trainings',
                                                )}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.sea_service &&
                                    activeTab === 'sea_service' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeSeaServiceTab
                                                employeeId={effectiveEmployeeId}
                                                sea_services={sea_services ?? []}
                                                vessel_types={vessel_types ?? []}
                                                ranks={ranks}
                                                clients={clients ?? []}
                                                employeeRankId={localEmployee.rank_id ?? null}
                                                canManage={can?.sea_service_manage ?? false}
                                                ensureEmployee={
                                                    isCreateMode
                                                        ? ensureEmployee
                                                        : undefined
                                                }
                                                templateFields={resolveTemplateTableFields(
                                                    employee_tabs.template_fields,
                                                    resolved_template?.fields,
                                                    'employee_sea_services',
                                                )}
                                            />
                                        )
                                    ) : null}
                                    {employee_tabs.documents && activeTab === 'documents' ? (
                                        recordsLoading ? (
                                            <EmployeeTabSkeleton />
                                        ) : (
                                            <EmployeeDocumentsTab
                                                employee={{
                                                    id: localEmployee.id as number,
                                                    name: localEmployee.name,
                                                }}
                                                documents={documents ?? []}
                                                document_types={document_types ?? []}
                                                can={{
                                                    documents_upload:
                                                        can?.documents_upload ?? false,
                                                    documents_download:
                                                        can?.documents_download ?? false,
                                                    documents_delete:
                                                        can?.documents_delete ?? false,
                                                }}
                                                ensureEmployee={
                                                    isCreateMode
                                                        ? ensureEmployee
                                                        : undefined
                                                }
                                                templateFields={resolveTemplateTableFields(
                                                    employee_tabs.template_fields,
                                                    resolved_template?.fields,
                                                    'employee_documents',
                                                )}
                                            />
                                        )
                                    ) : null}
                        </EmployeeProfileShell>
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
