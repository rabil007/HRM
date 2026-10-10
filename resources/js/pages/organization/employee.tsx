import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    Coins,
    UserCheck,
} from 'lucide-react';
import {
    lazy,
    Suspense,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { show } from '@/actions/App/Http/Controllers/Organization/EmployeeController';
import printEmployeeCv from '@/actions/App/Http/Controllers/Organization/EmployeeCvPrintController';
import printEmployeeOffshoreCv from '@/actions/App/Http/Controllers/Organization/EmployeeOffshoreCvPrintController';
import printEmployeeSalaryCertificate from '@/actions/App/Http/Controllers/Organization/EmployeeSalaryCertificatePrintController';
import printEmployeeSalaryDeclaration from '@/actions/App/Http/Controllers/Organization/EmployeeSalaryDeclarationPrintController';
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
import { useEmployeeProfileTabProps } from '@/features/organization/employees/profile/use-employee-profile-tab-props';
import { useEnsureEmployee } from '@/features/organization/employees/profile/use-ensure-employee';
import type { EnsuredEmployee } from '@/features/organization/employees/profile/use-ensure-employee';
import { actions } from '@/lib/design-system';
import { CreateEmployeeUserDialog } from '@/pages/organization/_components/create-employee-user-dialog';
import { EmployeeHeaderCard } from '@/pages/organization/_components/employee-header-card';
import { EmployeeMissingRequiredFieldsAlert } from '@/pages/organization/_components/employee-missing-required-fields-alert';
import { EmployeePersonalTab } from '@/pages/organization/_components/employee-personal-tab';
import { EmployeeProfileActionBar } from '@/pages/organization/_components/employee-profile-action-bar';
import { HireDateChangeWarningDialog } from '@/pages/organization/_components/hire-date-change-warning-dialog';
import { useEmployeeProfileForm } from '@/pages/organization/_hooks/use-employee-profile-form';
import type { UseEmployeeProfileFormResult } from '@/pages/organization/_hooks/use-employee-profile-form';
import {
    canEditEmployeeProfile,
    mergePersistedEmployeeAfterEnsure,
} from '@/pages/organization/_lib/employee-profile-persisted-state';
import type { HireDateChangePreview } from '@/pages/organization/_lib/hire-date-change-preview';
import { resolveTemplateTableFields } from '@/pages/organization/_lib/resolve-template-table-fields';
import type {
    DocumentTypeOption,
    EmployeeDetails,
    EmployeePageProps,
    EmployeeTab,
} from '@/pages/organization/employee-page.types';
import { employee as employeeContractsBrowse } from '@/routes/organization/contracts';
import { employee as employeeDocumentsBrowse } from '@/routes/organization/documents';

const EmployeeBankTab = lazy(() =>
    import('@/pages/organization/_components/employee-bank-tab').then(
        (module) => ({ default: module.EmployeeBankTab }),
    ),
);
const EmployeeContractTab = lazy(() =>
    import('@/pages/organization/_components/employee-contract-tab').then(
        (module) => ({ default: module.EmployeeContractTab }),
    ),
);
const EmployeeDocumentsTab = lazy(() =>
    import('@/pages/organization/_components/documents/employee-documents-tab').then(
        (module) => ({ default: module.EmployeeDocumentsTab }),
    ),
);
const EmployeeEducationTab = lazy(() =>
    import('@/pages/organization/_components/employee-education-tab').then(
        (module) => ({ default: module.EmployeeEducationTab }),
    ),
);
const EmployeeLanguagesTab = lazy(() =>
    import('@/pages/organization/_components/employee-languages-tab').then(
        (module) => ({ default: module.EmployeeLanguagesTab }),
    ),
);
const EmployeeSalaryRevisionsTab = lazy(() =>
    import('@/pages/organization/_components/employee-salary-revisions-tab').then(
        (module) => ({ default: module.EmployeeSalaryRevisionsTab }),
    ),
);
const EmployeeSeaServiceTab = lazy(() =>
    import('@/pages/organization/_components/employee-sea-service-tab').then(
        (module) => ({ default: module.EmployeeSeaServiceTab }),
    ),
);
const EmployeeTrainingTab = lazy(() =>
    import('@/pages/organization/_components/employee-training-tab').then(
        (module) => ({ default: module.EmployeeTrainingTab }),
    ),
);
const EmployeeVaccinationTab = lazy(() =>
    import('@/pages/organization/_components/employee-vaccination-tab').then(
        (module) => ({ default: module.EmployeeVaccinationTab }),
    ),
);
const EmployeeWorkExperienceTab = lazy(() =>
    import('@/pages/organization/_components/employee-work-experience-tab').then(
        (module) => ({ default: module.EmployeeWorkExperienceTab }),
    ),
);

const EMPLOYEE_PAGE_TAB_HASH_KEYS: Partial<Record<string, EmployeeTab>> = {
    '#contract': 'contract',
    '#salary-revisions': 'salary_revisions',
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

const EMPTY_DOCUMENT_TYPES: DocumentTypeOption[] = [];

export default function EmployeeDetails(props: EmployeePageProps) {
    // Create-mode key must NOT include employee.id. ensureEmployee / failed-save
    // redirects promote null → provisional id; keying on that remounts and wipes
    // entered form values. Successful finalize uses preserveState:'errors' (false
    // on success) so Inertia remounts a blank create page from server props.
    // Template changes still remount intentionally.
    const pageKey =
        props.mode === 'create'
            ? `create-${props.selected_profile_template_id ?? 'none'}`
            : `${props.employee.id}-${props.employee.updated_at}`;

    return <EmployeeDetailsPage key={pageKey} {...props} />;
}

function EmployeeDetailsPage({
    mode = 'edit',
    candidate_context = null,
    employee_navigation = null,
    resolved_template,
    profile_templates = [],
    selected_profile_template_id = null,
    employee,
    hire_date_change = { has_annual_leave_balances: false },
    contract_count,
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
    document_ai_settings,
    can,
    roles,
    branches,
    departments,
    positions,
    countries,
    religions,
    genders,
    visa_types,
    company_visa_types,
    approval_locations,
    sssa_options,
    banks,
    sea_service_positions,
    projects,
    profile_clients,
    vessel_types,
    vessels,
    clients,
    employee_tabs,
}: EmployeePageProps) {
    const isCreateMode = mode === 'create';

    const { auth } = usePage().props as unknown as {
        auth?: { permissions?: string[]; user?: { id: number } };
    };

    const [localEmployee, setLocalEmployee] = useState(employee);

    const persistedEmployee = useMemo((): EmployeeDetails => {
        const serverConfirmed =
            employee.id !== null &&
            employee.id === localEmployee.id &&
            employee.updated_at &&
            employee.updated_at !== localEmployee.updated_at;

        if (serverConfirmed) {
            return employee as EmployeeDetails;
        }

        return localEmployee as EmployeeDetails;
    }, [employee, localEmployee]);

    const linkedUser = employee.user ?? persistedEmployee.user;

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
    const [pendingEmployeeId, setPendingEmployeeId] = useState<number | null>(
        null,
    );
    const [unsavedDialogOpen, setUnsavedDialogOpen] = useState(false);
    const [createUserOpen, setCreateUserOpen] = useState(false);
    const [hireDateWarningOpen, setHireDateWarningOpen] = useState(false);
    const [hireDateWarningPreview, setHireDateWarningPreview] =
        useState<HireDateChangePreview | null>(null);
    const hireDateWarningContinueRef = useRef<(() => void) | null>(null);

    const handleHireDateWarningRequired = useCallback(
        (preview: HireDateChangePreview, continueSave: () => void) => {
            setHireDateWarningPreview(preview);
            hireDateWarningContinueRef.current = continueSave;
            setHireDateWarningOpen(true);
        },
        [],
    );

    const handleEnsured = useCallback(
        (ensured: EnsuredEmployee) => {
            setLocalEmployee((current) =>
                mergePersistedEmployeeAfterEnsure(current, ensured),
            );

            // Persist resume URL so refresh reloads the owned provisional draft.
            if (typeof window === 'undefined' || !isCreateMode) {
                return;
            }

            const search = new URLSearchParams(window.location.search);
            search.set('employee_id', String(ensured.id));

            if (selectedTemplateId) {
                search.set('profile_template_id', String(selectedTemplateId));
            } else {
                search.delete('profile_template_id');
            }

            const next = `${window.location.pathname}?${search.toString()}`;
            const current = `${window.location.pathname}${window.location.search}`;

            if (next !== current) {
                window.history.replaceState(null, '', next);
            }
        },
        [isCreateMode, selectedTemplateId],
    );

    const permissions = auth?.permissions ?? [];

    const canUpdate = canEditEmployeeProfile(permissions, {
        isCreateMode,
        persistedEmployeeNo: persistedEmployee.employee_no,
    });

    void branches;
    void departments;
    void positions;

    const ensureEmployee = useEnsureEmployee({
        employeeId: persistedEmployee.id,
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
        persistedEmployee,
        canUpdate,
        {
            ensureEmployee:
                isCreateMode && !candidate_context ? ensureEmployee : undefined,
            candidateContext: candidate_context,
            templateRequiredFields:
                employee_tabs.template_fields?.employees ??
                resolved_template?.fields?.employees,
            listQuery: employee_navigation?.list_query ?? {},
            hasAnnualLeaveBalances: hire_date_change.has_annual_leave_balances,
            savedHireDate: employee.hire_date ?? null,
            onHireDateWarningRequired: handleHireDateWarningRequired,
        },
    );

    useEffect(() => {
        formDraftRef.current = {
            name: String(form.data.name ?? ''),
            employee_no: String(form.data.employee_no ?? ''),
        };
    }, [form.data.name, form.data.employee_no]);

    const canViewLinkedUser = permissions.includes('users.view');
    const canViewAttendanceCalendar = permissions.includes(
        'attendance.leave-requests.view',
    );
    const canApproveLeaveRequests = permissions.includes(
        'attendance.leave-requests.approve',
    );
    const isOwnEmployeeProfile = linkedUser?.id === auth?.user?.id;
    const showAttendanceCalendarButton =
        !isCreateMode &&
        canViewAttendanceCalendar &&
        (canApproveLeaveRequests || isOwnEmployeeProfile);
    const attendanceCalendarUrl =
        localEmployee.id !== null
            ? `/attendance/calendar?employee_id=${localEmployee.id}&year=${new Date().getFullYear()}`
            : undefined;
    const canCreateUser =
        !isCreateMode && (can?.create_user ?? false) && linkedUser === null;

    const visitEmployeeProfile = useCallback(
        (employeeId: number) => {
            const listQuery = employee_navigation?.list_query ?? {};
            const baseUrl = show.url(
                { employee: employeeId },
                { query: listQuery },
            );
            const hash =
                typeof window !== 'undefined' ? window.location.hash : '';

            router.visit(hash ? `${baseUrl}${hash}` : baseUrl, {
                preserveScroll: true,
            });
        },
        [employee_navigation?.list_query],
    );

    const effectiveEmployeeId = localEmployee.id ?? null;

    const tabs = useMemo(() => {
        const builtTabs = buildEmployeeProfileTabs({
            employee_tabs,
            counts: {
                contracts:
                    contracts === undefined ? null : contracts.length || null,
                salary_revisions:
                    contracts === undefined
                        ? null
                        : contracts.reduce(
                              (total, contract) =>
                                  total +
                                  (contract.salary_revisions?.length ?? 0),
                              0,
                          ) || null,
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
        });

        return builtTabs;
    }, [
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
    ]);

    const activeTab = useMemo((): EmployeeTab => {
        if (tabs.some((t) => t.id === tabValue)) {
            return tabValue;
        }

        return tabs[0]?.id ?? 'personal';
    }, [tabs, tabValue]);

    const tabPageProps = useMemo(
        () => ({
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
            vessel_types,
            vessels,
            clients,
        }),
        [
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
            vessel_types,
            vessels,
            clients,
        ],
    );

    const recordsLoading = useEmployeeProfileTabProps({
        activeTab,
        isCreateMode,
        pageProps: tabPageProps,
    });

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
        const nextId =
            templateId === '' ? null : Number.parseInt(templateId, 10);
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
                        <HireDateChangeWarningDialog
                            preview={hireDateWarningPreview}
                            open={hireDateWarningOpen}
                            processing={form.processing}
                            onOpenChange={(open) => {
                                setHireDateWarningOpen(open);

                                if (!open) {
                                    setHireDateWarningPreview(null);
                                    hireDateWarningContinueRef.current = null;
                                }
                            }}
                            onConfirm={() => {
                                hireDateWarningContinueRef.current?.();
                                setHireDateWarningOpen(false);
                                setHireDateWarningPreview(null);
                                hireDateWarningContinueRef.current = null;
                            }}
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
                                    <AlertDialogCancel
                                        className={actions.dialogSecondary}
                                    >
                                        Stay
                                    </AlertDialogCancel>
                                    <AlertDialogAction
                                        className={actions.dialogSecondary}
                                        onClick={() => {
                                            const nextEmployeeId =
                                                pendingEmployeeId;

                                            discardChanges();

                                            if (pendingTab) {
                                                setTabValue(pendingTab);
                                            }

                                            if (nextEmployeeId !== null) {
                                                visitEmployeeProfile(
                                                    nextEmployeeId,
                                                );
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
                                            const nextEmployeeId =
                                                pendingEmployeeId;

                                            saveChanges(() => {
                                                if (pendingTab) {
                                                    setTabValue(pendingTab);
                                                }

                                                if (nextEmployeeId !== null) {
                                                    visitEmployeeProfile(
                                                        nextEmployeeId,
                                                    );
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

                        {candidate_context ? (
                            <div className="space-y-4 rounded-2xl border border-primary/20 bg-primary/5 p-5 shadow-sm">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex items-center gap-3">
                                        <div className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                            <UserCheck className="size-5" />
                                        </div>
                                        <div>
                                            <h1 className="text-lg font-semibold text-foreground">
                                                Review & Convert Joined
                                                Candidate
                                            </h1>
                                            <p className="text-xs text-muted-foreground">
                                                Review candidate details and
                                                complete required employee
                                                profile fields before saving.
                                                Contracts and payroll are not
                                                automatically activated.
                                            </p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Link
                                                href={`/organization/recruitment/candidates/${candidate_context.candidate_id}`}
                                            >
                                                <ArrowLeft className="mr-1.5 size-4" />
                                                Cancel & Return
                                            </Link>
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            className="bg-primary text-primary-foreground hover:bg-primary/90"
                                            onClick={() => saveChanges()}
                                            disabled={form.processing}
                                        >
                                            <UserCheck className="mr-1.5 size-4" />
                                            {form.processing
                                                ? 'Converting...'
                                                : 'Convert to Employee'}
                                        </Button>
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-3 border-t border-primary/10 pt-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <div className="text-xs">
                                        <span className="font-medium text-muted-foreground">
                                            Candidate:
                                        </span>
                                        <div className="mt-0.5 font-semibold text-foreground">
                                            {candidate_context.name}
                                        </div>
                                    </div>
                                    <div className="text-xs">
                                        <span className="font-medium text-muted-foreground">
                                            Requirement / Position:
                                        </span>
                                        <div className="mt-0.5 font-semibold text-foreground">
                                            #
                                            {
                                                candidate_context.requirement_number
                                            }{' '}
                                            • {candidate_context.position_title}
                                        </div>
                                    </div>
                                    <div className="text-xs">
                                        <span className="font-medium text-muted-foreground">
                                            Actual Joining Date:
                                        </span>
                                        <div className="mt-0.5 font-semibold text-emerald-600 dark:text-emerald-400">
                                            {candidate_context.actual_joining_date ??
                                                '—'}
                                        </div>
                                    </div>
                                    <div className="text-xs">
                                        <span className="font-medium text-muted-foreground">
                                            Client / Project:
                                        </span>
                                        <div className="mt-0.5 text-foreground">
                                            {candidate_context.client_name
                                                ? `${candidate_context.client_name} • `
                                                : ''}
                                            {candidate_context.project_title ||
                                                '—'}
                                        </div>
                                    </div>
                                </div>

                                {candidate_context.proposed_offer && (
                                    <div className="rounded-lg border border-border/60 bg-background/80 p-3 text-xs">
                                        <div className="flex items-center gap-1.5 font-medium text-muted-foreground">
                                            <Coins className="size-3.5 text-amber-500" />
                                            <span>
                                                Accepted Offer Compensation
                                                (Proposed Values For Review):
                                            </span>
                                        </div>
                                        <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                            <div>
                                                <span className="text-muted-foreground">
                                                    Basic Salary:
                                                </span>{' '}
                                                <span className="font-medium">
                                                    {
                                                        candidate_context
                                                            .proposed_offer
                                                            .currency
                                                    }{' '}
                                                    {candidate_context
                                                        .proposed_offer
                                                        .basic_salary ?? '0'}
                                                </span>
                                            </div>
                                            <div>
                                                <span className="text-muted-foreground">
                                                    Housing:
                                                </span>{' '}
                                                <span className="font-medium">
                                                    {
                                                        candidate_context
                                                            .proposed_offer
                                                            .currency
                                                    }{' '}
                                                    {candidate_context
                                                        .proposed_offer
                                                        .housing_allowance ??
                                                        '0'}
                                                </span>
                                            </div>
                                            <div>
                                                <span className="text-muted-foreground">
                                                    Transport:
                                                </span>{' '}
                                                <span className="font-medium">
                                                    {
                                                        candidate_context
                                                            .proposed_offer
                                                            .currency
                                                    }{' '}
                                                    {candidate_context
                                                        .proposed_offer
                                                        .transportation_allowance ??
                                                        '0'}
                                                </span>
                                            </div>
                                            <div>
                                                <span className="text-muted-foreground">
                                                    Other:
                                                </span>{' '}
                                                <span className="font-medium">
                                                    {
                                                        candidate_context
                                                            .proposed_offer
                                                            .currency
                                                    }{' '}
                                                    {candidate_context
                                                        .proposed_offer
                                                        .other_allowances ??
                                                        '0'}
                                                </span>
                                            </div>
                                        </div>
                                        <p className="mt-1.5 text-[11px] text-muted-foreground italic">
                                            Note: These amounts are for HR
                                            reference during profile creation.
                                            No contract or payroll record is
                                            automatically activated.
                                        </p>
                                    </div>
                                )}

                                {candidate_context.duplicate_matches?.length >
                                    0 && (
                                    <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs">
                                        <div className="flex items-center gap-1.5 font-semibold text-amber-900 dark:text-amber-200">
                                            <AlertCircle className="size-4 text-amber-600 dark:text-amber-400" />
                                            <span>
                                                Potential Duplicate Existing
                                                Employees Found (
                                                {
                                                    candidate_context
                                                        .duplicate_matches
                                                        .length
                                                }
                                                )
                                            </span>
                                        </div>
                                        <p className="mt-1 text-muted-foreground">
                                            The following existing employee
                                            records match candidate identifiers
                                            (email, phone, or name). Verify if
                                            this candidate is an existing
                                            employee before creating a new
                                            profile.
                                        </p>
                                        <div className="mt-2 space-y-1.5">
                                            {candidate_context.duplicate_matches.map(
                                                (match) => (
                                                    <div
                                                        key={match.id}
                                                        className="flex flex-col justify-between gap-1 rounded bg-background/90 p-2 sm:flex-row sm:items-center"
                                                    >
                                                        <div>
                                                            <span className="font-semibold">
                                                                {match.name}
                                                            </span>{' '}
                                                            <span className="text-muted-foreground">
                                                                (
                                                                {
                                                                    match.employee_no
                                                                }
                                                                )
                                                            </span>
                                                            {match.department_name && (
                                                                <span className="text-muted-foreground">
                                                                    {' '}
                                                                    •{' '}
                                                                    {
                                                                        match.department_name
                                                                    }
                                                                </span>
                                                            )}
                                                            {match.position_title && (
                                                                <span className="text-muted-foreground">
                                                                    {' '}
                                                                    •{' '}
                                                                    {
                                                                        match.position_title
                                                                    }
                                                                </span>
                                                            )}
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-[11px] text-muted-foreground">
                                                                Matched on:{' '}
                                                                {match.matched_on.join(
                                                                    ', ',
                                                                )}
                                                            </span>
                                                            {candidate_context.can_link_existing && (
                                                                <Button
                                                                    asChild
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="h-7 text-xs"
                                                                >
                                                                    <Link
                                                                        href={`/organization/recruitment/candidates/${candidate_context.candidate_id}`}
                                                                    >
                                                                        Link
                                                                        Candidate
                                                                    </Link>
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        ) : isCreateMode ? (
                            <div className="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card/40 p-4 md:flex-row md:items-end md:justify-between">
                                <div className="space-y-1">
                                    <h1 className="text-lg font-semibold text-foreground">
                                        New employee
                                    </h1>
                                    <p className="text-sm text-muted-foreground">
                                        Enter a name, then add details in any
                                        tab. The employee record is created when
                                        you first save.
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
                                            onValueChange={
                                                changeProfileTemplate
                                            }
                                            placeholder="All tabs and fields (default)"
                                        >
                                            <AppSelectItem value="">
                                                Default (show all)
                                            </AppSelectItem>
                                            {profile_templates.map(
                                                (template) => (
                                                    <AppSelectItem
                                                        key={template.id}
                                                        value={String(
                                                            template.id,
                                                        )}
                                                    >
                                                        {template.name}
                                                    </AppSelectItem>
                                                ),
                                            )}
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
                                printOffshoreCvUrl={printEmployeeOffshoreCv.url(
                                    { employee: localEmployee.id as number },
                                    { query: { format: 'pdf', inline: 1 } },
                                )}
                                printSalaryCertificateUrl={printEmployeeSalaryCertificate.url(
                                    { employee: localEmployee.id as number },
                                    { query: { format: 'pdf', inline: 1 } },
                                )}
                                printSalaryDeclarationUrl={printEmployeeSalaryDeclaration.url(
                                    { employee: localEmployee.id as number },
                                )}
                                canPrintSalaryCertificate={
                                    can?.salary_certificate_print ?? false
                                }
                                canPrintSalaryDeclaration={
                                    can?.salary_declaration_print ?? false
                                }
                                employeeNavigation={employee_navigation}
                                onNavigateEmployee={handleNavigateEmployee}
                                showDocumentsButton={
                                    employee_tabs.documents &&
                                    (can?.documents_view ?? false)
                                }
                                documentCount={
                                    documents === undefined
                                        ? null
                                        : documents.length
                                }
                                documentsBrowseUrl={employeeDocumentsBrowse.url(
                                    {
                                        employee: localEmployee.id as number,
                                    },
                                )}
                                showContractsButton={
                                    (can?.contracts_view ?? false) &&
                                    !isCreateMode &&
                                    localEmployee.id !== null
                                }
                                contractCount={contract_count ?? null}
                                contractsBrowseUrl={
                                    localEmployee.id
                                        ? employeeContractsBrowse.url({
                                              employee: localEmployee.id,
                                          })
                                        : undefined
                                }
                                showCreateUserButton={canCreateUser}
                                onCreateUser={() => setCreateUserOpen(true)}
                                linkedUser={linkedUser}
                                showLinkedUserButton={
                                    !isCreateMode &&
                                    canViewLinkedUser &&
                                    linkedUser !== null
                                }
                                showAttendanceCalendarButton={
                                    showAttendanceCalendarButton
                                }
                                attendanceCalendarUrl={attendanceCalendarUrl}
                            />
                        )}

                        <CreateEmployeeUserDialog
                            open={createUserOpen}
                            onOpenChange={setCreateUserOpen}
                            employee={employee}
                            roles={roles ?? []}
                            onSuccess={() => {
                                router.reload({ only: ['employee'] });
                            }}
                        />

                        <EmployeeHeaderCard
                            canUpdate={canUpdate}
                            canAssignProfileTemplate={
                                can?.assign_profile_template ?? false
                            }
                            canChangeProfileTemplate={
                                can?.change_profile_template ?? false
                            }
                            profileTemplates={profile_templates}
                            employee={persistedEmployee}
                            departments={departments ?? []}
                            positions={positions ?? []}
                            projects={projects ?? []}
                            clients={profile_clients ?? []}
                            countries={countries ?? []}
                            genders={genders ?? []}
                            religions={religions ?? []}
                            visa_types={visa_types ?? []}
                            company_visa_types={company_visa_types ?? []}
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
                            {employee_tabs.personal &&
                            activeTab === 'personal' ? (
                                <EmployeePersonalTab
                                    employee={persistedEmployee}
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
                            {employee_tabs.contract &&
                            activeTab === 'contract' ? (
                                recordsLoading ? (
                                    <EmployeeTabSkeleton />
                                ) : (
                                    <Suspense
                                        fallback={<EmployeeTabSkeleton />}
                                    >
                                        <EmployeeContractTab
                                            employeeId={effectiveEmployeeId}
                                            contracts={contracts ?? []}
                                            canCreate={
                                                can?.contracts_create ?? false
                                            }
                                            canUpdate={
                                                can?.contracts_update ?? false
                                            }
                                            canDelete={
                                                can?.contracts_delete ?? false
                                            }
                                            canCreateSalaryRevisions={
                                                can?.contracts_salary_revisions_create ??
                                                false
                                            }
                                            canUpdateSalaryRevisions={
                                                can?.contracts_salary_revisions_update ??
                                                false
                                            }
                                            canDeleteSalaryRevisions={
                                                can?.contracts_salary_revisions_delete ??
                                                false
                                            }
                                            contractShowFrom="profile"
                                            ensureEmployee={
                                                isCreateMode
                                                    ? ensureEmployee
                                                    : undefined
                                            }
                                            templateContractFields={resolveTemplateTableFields(
                                                employee_tabs.template_fields,
                                                resolved_template?.fields,
                                                'employee_contracts',
                                            )}
                                            companyVisaTypes={
                                                company_visa_types
                                            }
                                            employeeCompanyVisaTypeId={
                                                employee?.company_visa_type_id ??
                                                null
                                            }
                                        />
                                    </Suspense>
                                )
                            ) : null}
                            {employee_tabs.salary_revisions &&
                            activeTab === 'salary_revisions' ? (
                                recordsLoading ? (
                                    <EmployeeTabSkeleton />
                                ) : (
                                    <Suspense
                                        fallback={<EmployeeTabSkeleton />}
                                    >
                                        <EmployeeSalaryRevisionsTab
                                            employeeId={effectiveEmployeeId}
                                            contracts={contracts ?? []}
                                            canCreate={
                                                can?.contracts_salary_revisions_create ??
                                                false
                                            }
                                            canUpdate={
                                                can?.contracts_salary_revisions_update ??
                                                false
                                            }
                                            canDelete={
                                                can?.contracts_salary_revisions_delete ??
                                                false
                                            }
                                        />
                                    </Suspense>
                                )
                            ) : null}
                            {employee_tabs.bank && activeTab === 'bank' ? (
                                recordsLoading ? (
                                    <EmployeeTabSkeleton />
                                ) : (
                                    <Suspense
                                        fallback={<EmployeeTabSkeleton />}
                                    >
                                        <EmployeeBankTab
                                            employeeId={effectiveEmployeeId}
                                            bank_accounts={bank_accounts ?? []}
                                            banks={banks}
                                            canManage={
                                                can?.bank_accounts_manage ??
                                                false
                                            }
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
                                    </Suspense>
                                )
                            ) : null}
                            {employee_tabs.education !== false &&
                            activeTab === 'education' ? (
                                recordsLoading ? (
                                    <EmployeeTabSkeleton />
                                ) : (
                                    <Suspense
                                        fallback={<EmployeeTabSkeleton />}
                                    >
                                        <EmployeeEducationTab
                                            employeeId={effectiveEmployeeId}
                                            education_qualifications={
                                                education_qualifications ?? []
                                            }
                                            countries={countries}
                                            canCreate={
                                                can?.education_create ?? false
                                            }
                                            canUpdate={
                                                can?.education_update ?? false
                                            }
                                            canDelete={
                                                can?.education_delete ?? false
                                            }
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
                                    </Suspense>
                                )
                            ) : null}
                            {employee_tabs.work_experience !== false &&
                            activeTab === 'work_experience' ? (
                                recordsLoading ? (
                                    <EmployeeTabSkeleton />
                                ) : (
                                    <Suspense
                                        fallback={<EmployeeTabSkeleton />}
                                    >
                                        <EmployeeWorkExperienceTab
                                            employeeId={effectiveEmployeeId}
                                            work_experiences={
                                                work_experiences ?? []
                                            }
                                            canCreate={
                                                can?.work_experience_create ??
                                                false
                                            }
                                            canUpdate={
                                                can?.work_experience_update ??
                                                false
                                            }
                                            canDelete={
                                                can?.work_experience_delete ??
                                                false
                                            }
                                            canImport={
                                                can?.work_experience_import ??
                                                false
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
                                    </Suspense>
                                )
                            ) : null}
                            {employee_tabs.vaccination &&
                            activeTab === 'vaccination' ? (
                                recordsLoading ? (
                                    <EmployeeTabSkeleton />
                                ) : (
                                    <Suspense
                                        fallback={<EmployeeTabSkeleton />}
                                    >
                                        <EmployeeVaccinationTab
                                            employeeId={effectiveEmployeeId}
                                            vaccinations={vaccinations ?? []}
                                            countries={countries}
                                            canCreate={
                                                can?.vaccination_create ?? false
                                            }
                                            canUpdate={
                                                can?.vaccination_update ?? false
                                            }
                                            canDelete={
                                                can?.vaccination_delete ?? false
                                            }
                                            canImport={
                                                can?.vaccination_import ?? false
                                            }
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
                                    </Suspense>
                                )
                            ) : null}
                            {employee_tabs.languages !== false &&
                            activeTab === 'languages' ? (
                                recordsLoading ? (
                                    <EmployeeTabSkeleton />
                                ) : (
                                    <Suspense
                                        fallback={<EmployeeTabSkeleton />}
                                    >
                                        <EmployeeLanguagesTab
                                            employeeId={effectiveEmployeeId}
                                            languages={languages ?? []}
                                            canCreate={
                                                can?.languages_create ?? false
                                            }
                                            canUpdate={
                                                can?.languages_update ?? false
                                            }
                                            canDelete={
                                                can?.languages_delete ?? false
                                            }
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
                                    </Suspense>
                                )
                            ) : null}
                            {employee_tabs.training &&
                            activeTab === 'training' ? (
                                recordsLoading ? (
                                    <EmployeeTabSkeleton />
                                ) : (
                                    <Suspense
                                        fallback={<EmployeeTabSkeleton />}
                                    >
                                        <EmployeeTrainingTab
                                            employeeId={effectiveEmployeeId}
                                            employeeName={employee.name}
                                            trainings={trainings ?? []}
                                            courses={courses ?? []}
                                            countries={countries}
                                            canCreate={
                                                can?.training_create ?? false
                                            }
                                            canUpdate={
                                                can?.training_update ?? false
                                            }
                                            canDelete={
                                                can?.training_delete ?? false
                                            }
                                            canImport={
                                                can?.training_import ?? false
                                            }
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
                                    </Suspense>
                                )
                            ) : null}
                            {employee_tabs.sea_service &&
                            activeTab === 'sea_service' ? (
                                recordsLoading ? (
                                    <EmployeeTabSkeleton />
                                ) : (
                                    <Suspense
                                        fallback={<EmployeeTabSkeleton />}
                                    >
                                        <EmployeeSeaServiceTab
                                            employeeId={effectiveEmployeeId}
                                            employeeNo={
                                                localEmployee.employee_no
                                            }
                                            employeeName={localEmployee.name}
                                            sea_services={sea_services ?? []}
                                            vessel_types={vessel_types ?? []}
                                            vessels={vessels ?? []}
                                            positions={
                                                sea_service_positions ?? []
                                            }
                                            clients={clients ?? []}
                                            employeePositionId={
                                                localEmployee.position?.id ??
                                                null
                                            }
                                            canManage={
                                                can?.sea_service_manage ?? false
                                            }
                                            canCreate={
                                                can?.sea_service_create ?? false
                                            }
                                            canUpdate={
                                                can?.sea_service_update ?? false
                                            }
                                            canDelete={
                                                can?.sea_service_delete ?? false
                                            }
                                            canImport={
                                                can?.sea_service_import ?? false
                                            }
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
                                    </Suspense>
                                )
                            ) : null}
                            {employee_tabs.documents &&
                            activeTab === 'documents' ? (
                                recordsLoading ? (
                                    <EmployeeTabSkeleton />
                                ) : (
                                    <Suspense
                                        fallback={<EmployeeTabSkeleton />}
                                    >
                                        <EmployeeDocumentsTab
                                            employee={{
                                                id: localEmployee.id as number,
                                                name: localEmployee.name,
                                                employee_no:
                                                    localEmployee.employee_no,
                                            }}
                                            documents={documents ?? []}
                                            document_types={
                                                document_types ??
                                                EMPTY_DOCUMENT_TYPES
                                            }
                                            can={{
                                                documents_upload:
                                                    can?.documents_upload ??
                                                    false,
                                                documents_download:
                                                    can?.documents_download ??
                                                    false,
                                                documents_delete:
                                                    can?.documents_delete ??
                                                    false,
                                            }}
                                            documentAiSettings={
                                                document_ai_settings
                                            }
                                            canUseDocumentAi={
                                                can?.documents_ai_use ?? false
                                            }
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
                                    </Suspense>
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
