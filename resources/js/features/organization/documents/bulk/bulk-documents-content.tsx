import { Link, router, usePoll } from '@inertiajs/react';
import { Download, Eye, Folder, Loader2, Mail, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import GenerateCustomDocumentsController from '@/actions/App/Http/Controllers/Organization/BulkDocuments/GenerateCustomDocumentsController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { ConfirmDeleteDialog } from '@/components/confirm-delete-dialog';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
    dataTableActionsCellClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SelectionToolbar } from '@/components/selection/selection-toolbar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { BulkDocumentsHistoryTable } from '@/features/organization/documents/bulk/bulk-documents-history-table';
import { BulkDocumentsViewSwitcher } from '@/features/organization/documents/bulk/bulk-documents-view-switcher';
import type { BulkDocumentsView } from '@/features/organization/documents/bulk/bulk-documents-view-switcher';
import { BulkEmailBatchSendsSheet } from '@/features/organization/documents/bulk/bulk-email-batch-sends-sheet';
import { BulkDocumentsEmailModal } from '@/features/organization/documents/bulk/bulk-email-modal';
import {
    DocumentJourneySheet,
    badgeToneClasses,
} from '@/features/organization/documents/journey/document-journey-sheet';
import { bulkDocumentsPollOnlyProps } from '@/features/organization/documents/lib/bulk-documents-poll-props';
import { generationActionLabel } from '@/features/organization/documents/lib/generation-action-label';
import {
    generationCompletionToast,
    isGenerationRunActive,
    rosterGenerationBadge,
    shouldPollBulkDocuments,
} from '@/features/organization/documents/lib/generation-run-progress';
import { downloadBulkZip } from '@/features/organization/documents/shared/download-bulk-zip';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { useRecordSelection } from '@/hooks/use-record-selection';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import documentRoutes, {
    activity as documentsActivity,
    generate as documentsGenerate,
    templates as documentsTemplates,
} from '@/routes/organization/documents';
import { DocumentContextHeader } from './components/document-context-header';
import { EmployeeFilters } from './components/employee-filters';
import { GenerationProgressBanner } from './components/generation-progress-banner';
import { GenerationStatusFilter } from './components/generation-status-filter';
import { RegenerationWarning } from './components/regeneration-warning';
import { EMPTY_BULK_DOCUMENT_FILTERS } from './types';
import type {
    BulkDocumentFilters,
    BulkDocumentsPageProps,
    BulkEmailFilter,
    BulkRosterEmployee,
    LatestEmailBatch,
    ProcessLifecycleFilter,
} from './types';

function documentsSectionUrl(view: BulkDocumentsView): string {
    if (view === 'history') {
        return documentsActivity.url();
    }

    return documentsGenerate.url();
}

function buildQuery(
    documentTypeKey: string,
    filters: BulkDocumentFilters,
    search: string,
    processFilter: ProcessLifecycleFilter = 'all',
    emailFilter: BulkEmailFilter = 'all',
    pagination?: { page?: number | null; perPage: number },
): Record<string, string> {
    const query: Record<string, string> = {
        document_type_key: documentTypeKey,
        per_page: String(pagination?.perPage ?? 20),
    };

    if (search.trim()) {
        query.search = search.trim();
    }

    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            query[key] = value;
        }
    });

    if (processFilter !== 'all') {
        query.process_filter = processFilter;
    }

    if (emailFilter === 'emailed' || emailFilter === 'not_emailed') {
        query.email_filter = emailFilter;
    }

    if (pagination?.page) {
        query.page = String(pagination.page);
    }

    return query;
}

function EmailProgressBanner({
    latestEmailBatch,
}: {
    latestEmailBatch: LatestEmailBatch | null;
}) {
    const [dismissedBatchId, setDismissedBatchId] = useState<number | null>(
        null,
    );

    const status = latestEmailBatch?.status;
    const isRunning = status === 'running' || status === 'queued';
    const isFailed = status === 'failed';
    const isCompleted = status === 'completed';

    useEffect(() => {
        if (!latestEmailBatch || !isCompleted) {
            return;
        }

        const batchId = latestEmailBatch.id;
        const timeout = window.setTimeout(() => {
            setDismissedBatchId(batchId);
        }, 6000);

        return () => window.clearTimeout(timeout);
    }, [isCompleted, latestEmailBatch]);

    if (!latestEmailBatch) {
        return null;
    }

    if (!isRunning && !isFailed && !isCompleted) {
        return null;
    }

    if (!isRunning && dismissedBatchId === latestEmailBatch.id) {
        return null;
    }

    const processed =
        latestEmailBatch.sent_count +
        latestEmailBatch.failed_count +
        latestEmailBatch.skipped_no_email_count;

    let message = '';

    if (isRunning) {
        message = `Sending emails… ${processed} of ${latestEmailBatch.total_selected} processed`;
    } else if (isCompleted) {
        const parts = [`${latestEmailBatch.sent_count} sent`];

        if (latestEmailBatch.skipped_no_email_count > 0) {
            parts.push(
                `${latestEmailBatch.skipped_no_email_count} skipped (no email)`,
            );
        }

        if (latestEmailBatch.failed_count > 0) {
            parts.push(`${latestEmailBatch.failed_count} failed`);
        }

        message = parts.join(' · ');
    } else {
        message = 'Email sending failed. Please try again.';
    }

    return (
        <div
            className={cn(
                'mb-6 flex items-center gap-3 rounded-xl border px-4 py-3 text-sm',
                isRunning &&
                    'border-sky-500/25 bg-sky-500/6 text-sky-700 dark:text-sky-400',
                isCompleted &&
                    'border-emerald-500/25 bg-emerald-500/6 text-emerald-700 dark:text-emerald-400',
                isFailed &&
                    'border-destructive/25 bg-destructive/6 text-destructive',
            )}
        >
            <Mail className="h-4 w-4 shrink-0" />
            {isRunning ? (
                <Spinner className="h-4 w-4 shrink-0" />
            ) : (
                <span
                    className={cn(
                        'flex h-2 w-2 shrink-0 rounded-full',
                        isCompleted && 'bg-emerald-500',
                        isFailed && 'bg-destructive',
                    )}
                />
            )}
            <span className="font-medium">{message}</span>
            {!isRunning ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="ml-auto h-6 w-6 shrink-0 rounded-full hover:bg-foreground/10"
                    onClick={() => setDismissedBatchId(latestEmailBatch.id)}
                    aria-label="Dismiss"
                >
                    <X className="h-3.5 w-3.5" />
                </Button>
            ) : null}
        </div>
    );
}

export function BulkDocumentsContent({
    document_type_key,
    document_type_options,
    is_custom_template,
    custom_template,
    view,
    module_view_locked = false,
    can_view_templates = false,
    filters: initialFilters,
    search: initialSearch,
    counts,
    employees,
    activity,
    pagination,
    process_filter: initialProcessFilter = 'all',
    email_filter,
    company_visa_types,
    department_tree,
    department_tree_selected_id,
    department_tree_selected_position_id,
    company_name,
    email_template,
    latest_run,
    latest_email_batch,
    can,
}: BulkDocumentsPageProps) {
    const isRosterView = view === 'roster';
    const isHistoryView = view === 'history';
    const [searchInput, setSearchInput] = useState(initialSearch);
    const [processFilter, setProcessFilter] =
        useState<ProcessLifecycleFilter>(initialProcessFilter);
    const [filters, setFilters] = useState<BulkDocumentFilters>({
        department_id: initialFilters.department_id,
        position_id: initialFilters.position_id,
        company_visa_type_id: initialFilters.company_visa_type_id,
    });
    const [emailOpen, setEmailOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [selectedEmailBatchId, setSelectedEmailBatchId] = useState<
        number | null
    >(null);
    const [isGenerating, setIsGenerating] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [isDownloading, setIsDownloading] = useState(false);
    const [matchingSelection, setMatchingSelection] = useState<{
        employee_ids: number[];
        document_ids: number[];
        total: number;
    } | null>(null);
    const [isSelectingAllMatching, setIsSelectingAllMatching] = useState(false);
    const [journeySheetOpen, setJourneySheetOpen] = useState(false);
    const [journeyIdentifiers, setJourneyIdentifiers] = useState<{
        document_instance_id?: number | null;
        employee_document_id?: number | null;
        employee_id?: number | null;
        version_id?: number | null;
        document_type_key?: string | null;
        generation_run_id?: number | null;
    } | null>(null);

    useEffect(() => {
        setProcessFilter(initialProcessFilter);
    }, [initialProcessFilter]);

    const employeeIds = useMemo(
        () => employees.map((employee) => employee.id),
        [employees],
    );

    const {
        selectedIds,
        selectedCount,
        isSelected,
        isAllSelected,
        isPartiallySelected,
        toggle,
        toggleAll,
        clear,
    } = useRecordSelection(employeeIds);

    const selectedEmployees = useMemo(
        () => employees.filter((employee) => selectedIds.includes(employee.id)),
        [employees, selectedIds],
    );

    const selectedDocumentIds = useMemo(
        () =>
            selectedEmployees
                .map((employee) => employee.document?.id)
                .filter((id): id is number => id !== undefined),
        [selectedEmployees],
    );

    const effectiveSelectedIds = matchingSelection?.employee_ids ?? selectedIds;
    const effectiveSelectedCount = matchingSelection?.total ?? selectedCount;
    const effectiveDocumentIds =
        matchingSelection?.document_ids ?? selectedDocumentIds;

    const clearSelection = useCallback(() => {
        clear();
        setMatchingSelection(null);
    }, [clear]);

    useEffect(() => {
        setMatchingSelection(null);
    }, [document_type_key, filters, processFilter, searchInput]);

    const previewEmployee = useMemo(() => {
        if (matchingSelection) {
            return employees[0] ?? null;
        }

        return selectedEmployees[0] ?? null;
    }, [employees, matchingSelection, selectedEmployees]);

    const selectedTypeOption = document_type_options.find(
        (option) => option.value === document_type_key,
    );

    const selectedTypeLabel = selectedTypeOption?.label ?? document_type_key;

    const isCustomTemplate = Boolean(
        is_custom_template ||
        selectedTypeOption?.is_custom ||
        document_type_key.startsWith('custom_'),
    );

    const customTemplateId =
        custom_template?.id ??
        selectedTypeOption?.template_id ??
        (document_type_key.startsWith('custom_')
            ? Number(document_type_key.replace('custom_', ''))
            : undefined);

    const handleOpenJourney = useCallback(
        (employee: BulkRosterEmployee) => {
            setJourneyIdentifiers({
                employee_id: employee.id,
                employee_document_id: employee.document?.id ?? null,
                document_type_key: document_type_key || null,
                version_id: customTemplateId ?? null,
                generation_run_id: latest_run?.id ?? null,
            });
            setJourneySheetOpen(true);
        },
        [customTemplateId, document_type_key, latest_run?.id],
    );

    const missingCount = counts.not_generated;
    const generateLabel = generationActionLabel({
        isBusy: isGenerating || isGenerationRunActive(latest_run?.status),
        selectedCount: effectiveSelectedCount,
        missingCount,
    });

    const showSelectAllMatching =
        isAllSelected &&
        matchingSelection === null &&
        pagination.total > selectedCount;

    const handleSelectAllMatching = async () => {
        setIsSelectingAllMatching(true);

        try {
            const params = new URLSearchParams(
                buildQuery(
                    document_type_key,
                    filters,
                    searchInput,
                    processFilter,
                    email_filter,
                    { perPage: pagination.per_page },
                ),
            );

            const response = await fetch(
                `/organization/documents/bulk/selection?${params.toString()}`,
                {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            );

            if (!response.ok) {
                throw new Error('Failed to load selection.');
            }

            const data = (await response.json()) as {
                employee_ids: number[];
                document_ids: number[];
                total: number;
            };

            setMatchingSelection(data);
        } catch {
            toast.error('Could not select all matching employees.');
        } finally {
            setIsSelectingAllMatching(false);
        }
    };

    const handleToggleEmployee = (employeeId: number) => {
        setMatchingSelection(null);
        toggle(employeeId);
    };

    const handleToggleAllEmployees = () => {
        if (matchingSelection) {
            clearSelection();

            return;
        }

        toggleAll();
    };

    const isEmployeeRowSelected = (employeeId: number) =>
        matchingSelection
            ? matchingSelection.employee_ids.includes(employeeId)
            : isSelected(employeeId);

    const isHeaderCheckboxChecked = matchingSelection
        ? true
        : isAllSelected
          ? true
          : isPartiallySelected
            ? 'indeterminate'
            : false;

    const isRunActive = isGenerationRunActive(latest_run?.status);

    const isEmailBatchActive =
        latest_email_batch?.status === 'running' ||
        latest_email_batch?.status === 'queued';

    const { start, stop } = usePoll(
        3000,
        {
            only: bulkDocumentsPollOnlyProps(),
        },
        { autoStart: false },
    );

    useEffect(() => {
        const shouldPollRoster =
            isRosterView &&
            shouldPollBulkDocuments(isRunActive, isEmailBatchActive);

        if (!shouldPollRoster) {
            stop();

            return;
        }

        start();

        return () => {
            stop();
        };
    }, [isRunActive, isEmailBatchActive, isRosterView, start, stop]);

    const previousRunId = useRef(latest_run?.id);
    const previousRunStatus = useRef(latest_run?.status);
    useEffect(() => {
        const previous = previousRunStatus.current;
        const sameRun = latest_run?.id === previousRunId.current;

        if (
            sameRun &&
            isGenerationRunActive(previous) &&
            latest_run !== null &&
            (latest_run.status === 'completed' ||
                latest_run.status === 'failed')
        ) {
            const result = generationCompletionToast(latest_run);

            if (result.type === 'error') {
                toast.error(`${result.title}. ${result.body}`);
            } else if (result.type === 'warning') {
                toast.warning(`${result.title}. ${result.body}`);
            } else {
                toast.success(`${result.title}. ${result.body}`);
            }
        }

        previousRunId.current = latest_run?.id;
        previousRunStatus.current = latest_run?.status;
    }, [latest_run]);

    const previousEmailBatchStatus = useRef(latest_email_batch?.status);
    useEffect(() => {
        const previous = previousEmailBatchStatus.current;

        if (
            (previous === 'running' || previous === 'queued') &&
            latest_email_batch?.status === 'completed'
        ) {
            toast.success('Email sending completed.');
        }

        if (
            (previous === 'running' || previous === 'queued') &&
            latest_email_batch?.status === 'failed'
        ) {
            toast.error('Email sending failed.');
        }

        previousEmailBatchStatus.current = latest_email_batch?.status;
    }, [latest_email_batch?.status]);

    const navigate = useCallback(
        (
            nextType = document_type_key,
            nextFilters = filters,
            nextSearch = searchInput,
            nextProcessFilter = processFilter,
            nextView: BulkDocumentsView = view,
            nextEmailFilter: BulkEmailFilter = email_filter,
            page: number | null = null,
        ) => {
            router.get(
                documentsSectionUrl(nextView),
                buildQuery(
                    nextType,
                    nextFilters,
                    nextSearch,
                    nextProcessFilter,
                    nextEmailFilter,
                    {
                        page,
                        perPage: pagination.per_page,
                    },
                ),
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [
            document_type_key,
            email_filter,
            filters,
            processFilter,
            pagination.per_page,
            searchInput,
            view,
        ],
    );

    const setView = useCallback(
        (nextView: BulkDocumentsView) => {
            clearSelection();
            navigate(
                document_type_key,
                filters,
                searchInput,
                processFilter,
                nextView,
                email_filter,
                null,
            );
        },
        [
            clearSelection,
            document_type_key,
            email_filter,
            filters,
            processFilter,
            navigate,
            searchInput,
        ],
    );

    const goToPage = useCallback(
        (page: number) => {
            navigate(
                document_type_key,
                filters,
                searchInput,
                processFilter,
                view,
                email_filter,
                page,
            );
        },
        [
            document_type_key,
            email_filter,
            filters,
            processFilter,
            navigate,
            searchInput,
            view,
        ],
    );

    const setPerPage = useCallback(
        (perPage: number) => {
            router.get(
                documentsSectionUrl(view),
                buildQuery(
                    document_type_key,
                    filters,
                    searchInput,
                    processFilter,
                    email_filter,
                    { perPage },
                ),
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [
            document_type_key,
            email_filter,
            filters,
            processFilter,
            searchInput,
            view,
        ],
    );

    useEffect(() => {
        if (!isRosterView) {
            return;
        }

        const timeout = window.setTimeout(() => {
            if (searchInput !== initialSearch) {
                navigate(document_type_key, filters, searchInput);
            }
        }, 400);

        return () => window.clearTimeout(timeout);
    }, [
        document_type_key,
        filters,
        initialSearch,
        isRosterView,
        navigate,
        searchInput,
    ]);

    const handleGenerate = () => {
        if (!can.generate || isGenerating || isRunActive) {
            return;
        }

        setIsGenerating(true);

        if (isCustomTemplate && customTemplateId) {
            router.post(
                GenerateCustomDocumentsController.url(),
                {
                    document_generation_template_id: customTemplateId,
                    status: 'active',
                    ...filters,
                    search: searchInput,
                    ...(effectiveSelectedCount > 0
                        ? { employee_ids: effectiveSelectedIds }
                        : {}),
                },
                {
                    preserveScroll: true,
                    onFinish: () => setIsGenerating(false),
                },
            );

            return;
        }

        router.post(
            '/organization/documents/bulk/generate',
            {
                document_type_key,
                status: 'active',
                ...filters,
                search: searchInput,
                ...(effectiveSelectedCount > 0
                    ? { employee_ids: effectiveSelectedIds }
                    : {}),
            },
            {
                preserveScroll: true,
                onFinish: () => setIsGenerating(false),
            },
        );
    };

    const handleDelete = () => {
        if (!can.delete || isDeleting || effectiveDocumentIds.length === 0) {
            return;
        }

        setIsDeleting(true);

        router.delete('/organization/documents/bulk/documents', {
            data: {
                document_type_key,
                document_ids: effectiveDocumentIds,
            },
            preserveScroll: true,
            onSuccess: () => {
                setDeleteOpen(false);
                clearSelection();
            },
            onFinish: () => setIsDeleting(false),
        });
    };

    const handleDownload = async () => {
        if (!can.download || isDownloading) {
            return;
        }

        const employeeIdsForDownload = matchingSelection
            ? matchingSelection.employee_ids
            : selectedEmployees
                  .filter((employee) => employee.document !== null)
                  .map((employee) => employee.id);

        if (employeeIdsForDownload.length === 0) {
            toast.error('No generated documents in the current selection.');

            return;
        }

        setIsDownloading(true);

        try {
            await downloadBulkZip('/organization/documents/bulk/download', {
                document_type_key,
                employee_ids: employeeIdsForDownload,
            });
        } catch (error) {
            toast.error(
                error instanceof Error ? error.message : 'Download failed.',
            );
        } finally {
            setIsDownloading(false);
        }
    };

    const handleProcessFilterChange = useCallback(
        (next: ProcessLifecycleFilter) => {
            setProcessFilter(next);
            clearSelection();
            navigate(
                document_type_key,
                filters,
                searchInput,
                next,
                view,
                email_filter,
                null,
            );
        },
        [
            clearSelection,
            document_type_key,
            email_filter,
            filters,
            navigate,
            searchInput,
            view,
        ],
    );

    const setEmailFilter = useCallback(
        (next: BulkEmailFilter) => {
            navigate(
                document_type_key,
                filters,
                searchInput,
                processFilter,
                view,
                next,
            );
        },
        [
            document_type_key,
            filters,
            processFilter,
            navigate,
            searchInput,
            view,
        ],
    );

    const employeeFilterCount = [
        filters.department_id,
        filters.position_id,
        filters.company_visa_type_id,
        searchInput.trim(),
        email_filter !== 'all',
    ].filter(Boolean).length;

    const activeFilterCount = isRosterView
        ? employeeFilterCount + (processFilter !== 'all' ? 1 : 0)
        : employeeFilterCount;

    const clearAllFilters = useCallback(() => {
        const nextFilters = { ...EMPTY_BULK_DOCUMENT_FILTERS };

        setFilters(nextFilters);
        setSearchInput('');
        navigate(document_type_key, nextFilters, '', 'all', view, 'all');
    }, [document_type_key, navigate, view]);

    return (
        <Main>
            <PageHeader
                className="mb-6"
                title={isHistoryView ? 'Activity' : 'Generate & Track'}
                description={
                    isHistoryView
                        ? 'Review document generation and email history.'
                        : isCustomTemplate && custom_template
                          ? `Company template · Version ${custom_template.version}`
                          : document_type_key === ''
                            ? 'Choose a company template or built-in document to generate.'
                            : `Generate documents for multiple employees, track their review and signing journey, and manage delivery.`
                }
                right={
                    can_view_templates ? (
                        <Button asChild variant="outline" className="gap-1.5">
                            <Link href={documentsTemplates.url()}>
                                Manage Templates
                            </Link>
                        </Button>
                    ) : null
                }
            />

            {isRosterView ? (
                document_type_key !== '' ? (
                    <DocumentContextHeader
                        documentTypeKey={document_type_key}
                        documentTypeOptions={document_type_options}
                        missingCount={counts.not_generated}
                        selectedCount={effectiveSelectedCount}
                        generateLabel={generateLabel}
                        canGenerate={can.generate}
                        isGenerating={isGenerating || isRunActive}
                        onDocumentTypeChange={(value) => navigate(value)}
                        onGenerate={handleGenerate}
                    />
                ) : null
            ) : (
                <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-muted-foreground">
                        Generation and email history for{' '}
                        <span className="font-medium text-foreground">
                            {selectedTypeLabel}
                        </span>
                        .
                    </p>
                    <AppSelect
                        value={document_type_key}
                        onValueChange={(value) => navigate(value)}
                        className="h-10 w-full rounded-xl sm:w-64"
                    >
                        {document_type_options.map((option) => (
                            <AppSelectItem
                                key={option.value}
                                value={option.value}
                                keywords={option.category}
                            >
                                {option.category === 'Company Templates'
                                    ? `📄 ${option.label}`
                                    : option.label}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                </div>
            )}

            {isRosterView && document_type_key === '' ? (
                <Alert className="mb-6">
                    <AlertTitle>No templates available</AlertTitle>
                    <AlertDescription>
                        Upload a company PDF template or keep a current built-in
                        document such as Salary Certificate to start generating.
                    </AlertDescription>
                </Alert>
            ) : null}

            {isRosterView && document_type_key !== '' ? (
                <section className="my-6 overflow-hidden rounded-2xl border border-border/60 bg-card/55 shadow-xs">
                    <div className="flex flex-col gap-4 border-b border-border/60 px-4 py-4 sm:px-5 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="text-sm font-semibold text-foreground">
                                Employee roster
                            </h2>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Filter the roster, then select the people you
                                want to process.
                            </p>
                        </div>
                        <GenerationStatusFilter
                            processFilter={processFilter}
                            onFilterChange={handleProcessFilterChange}
                            counts={counts}
                        />
                    </div>
                    <div className="p-4 sm:p-5">
                        <EmployeeFilters
                            searchInput={searchInput}
                            onSearchChange={setSearchInput}
                            filters={{
                                ...filters,
                                search: searchInput,
                            }}
                            onFiltersChange={(next) => {
                                // eslint-disable-next-line @typescript-eslint/no-unused-vars
                                const { search: _, ...filtersOnly } = next;
                                setFilters(filtersOnly);
                                navigate(
                                    document_type_key,
                                    filtersOnly,
                                    searchInput,
                                );
                            }}
                            emailFilter={email_filter}
                            onEmailFilterChange={setEmailFilter}
                            companyVisaTypes={company_visa_types}
                            departmentTree={department_tree}
                            departmentTreeSelectedId={
                                department_tree_selected_id
                            }
                            departmentTreeSelectedPositionId={
                                department_tree_selected_position_id
                            }
                            activeFilterCount={activeFilterCount}
                            onClearFilters={clearAllFilters}
                        />
                    </div>
                </section>
            ) : null}

            {isRosterView ? (
                <GenerationProgressBanner
                    latestRun={latest_run}
                    onShowGenerated={() =>
                        handleProcessFilterChange('completed')
                    }
                    canViewTemplates={can_view_templates}
                />
            ) : null}
            {isRosterView ? (
                <EmailProgressBanner latestEmailBatch={latest_email_batch} />
            ) : null}

            {isRosterView ? (
                <>
                    {effectiveSelectedCount > 0 ? (
                        <RegenerationWarning
                            count={
                                employees.filter(
                                    (emp) =>
                                        effectiveSelectedIds.includes(emp.id) &&
                                        emp.document !== null,
                                ).length
                            }
                        />
                    ) : null}

                    <SelectionToolbar
                        count={effectiveSelectedCount}
                        itemLabel="employees"
                        onClear={clearSelection}
                        selectAllMatching={
                            showSelectAllMatching
                                ? {
                                      total: pagination.total,
                                      onSelect: () =>
                                          void handleSelectAllMatching(),
                                      loading: isSelectingAllMatching,
                                  }
                                : undefined
                        }
                        selectAll={
                            <Checkbox
                                checked={isHeaderCheckboxChecked}
                                onCheckedChange={handleToggleAllEmployees}
                                aria-label="Select all employees"
                            />
                        }
                        actions={
                            <>
                                {can.generate ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={handleGenerate}
                                        disabled={isGenerating || isRunActive}
                                    >
                                        {isGenerating || isRunActive ? (
                                            <Loader2 className="mr-2 h-3.5 w-3.5 animate-spin" />
                                        ) : null}
                                        {generateLabel}
                                    </Button>
                                ) : null}
                                {can.download ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => void handleDownload()}
                                        disabled={isDownloading}
                                    >
                                        <Download className="mr-2 h-3.5 w-3.5" />
                                        Download
                                    </Button>
                                ) : null}
                                {can.email && !isCustomTemplate ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setEmailOpen(true)}
                                    >
                                        <Mail className="mr-2 h-3.5 w-3.5" />
                                        Email document copy
                                    </Button>
                                ) : null}
                                {can.delete ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="text-destructive hover:text-destructive"
                                        onClick={() => setDeleteOpen(true)}
                                        disabled={
                                            effectiveDocumentIds.length === 0
                                        }
                                    >
                                        <Trash2 className="mr-2 h-3.5 w-3.5" />
                                        Delete
                                    </Button>
                                ) : null}
                            </>
                        }
                    />

                    <OrganizationDataTable
                        minWidth="min-w-[880px]"
                        header={
                            <>
                                <div>
                                    <p className="text-sm font-semibold text-foreground">
                                        Employees
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {pagination.total}{' '}
                                        {pagination.total === 1
                                            ? 'employee'
                                            : 'employees'}{' '}
                                        in this view
                                    </p>
                                </div>
                                {!module_view_locked ? (
                                    <BulkDocumentsViewSwitcher
                                        value={view}
                                        onChange={setView}
                                    />
                                ) : null}
                            </>
                        }
                    >
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead className="w-10">
                                    <Checkbox
                                        checked={isHeaderCheckboxChecked}
                                        onCheckedChange={
                                            handleToggleAllEmployees
                                        }
                                        aria-label="Select all employees"
                                    />
                                </DataTableHead>
                                <DataTableHead>Employee</DataTableHead>
                                <DataTableHead>Process</DataTableHead>
                                <DataTableHead>Waiting For</DataTableHead>
                                <DataTableHead>Last Activity</DataTableHead>
                                <DataTableHead className="text-right">
                                    Actions
                                </DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {employees.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={6} className="p-0">
                                        <EmptyState
                                            title="No employees match the current filters."
                                            description="Try adjusting your search or filters."
                                        />
                                    </TableCell>
                                </TableRow>
                            ) : (
                                employees.map((employee) => (
                                    <BulkRosterRow
                                        key={employee.id}
                                        employee={employee}
                                        checked={isEmployeeRowSelected(
                                            employee.id,
                                        )}
                                        onToggle={() =>
                                            handleToggleEmployee(employee.id)
                                        }
                                        canDownload={can.download}
                                        onViewJourney={() =>
                                            handleOpenJourney(employee)
                                        }
                                    />
                                ))
                            )}
                        </TableBody>
                    </OrganizationDataTable>

                    <Pagination
                        currentPage={pagination.current_page}
                        lastPage={pagination.last_page}
                        from={pagination.from}
                        to={pagination.to}
                        total={pagination.total}
                        perPage={pagination.per_page}
                        onPageChange={goToPage}
                        onPerPageChange={setPerPage}
                        label="employees"
                    />
                </>
            ) : (
                <>
                    <BulkDocumentsHistoryTable
                        activity={activity}
                        onEmailBatchClick={setSelectedEmailBatchId}
                        header={
                            <>
                                <span className="text-sm font-medium text-foreground">
                                    Recent Operations
                                </span>
                                {!module_view_locked ? (
                                    <BulkDocumentsViewSwitcher
                                        value={view}
                                        onChange={setView}
                                    />
                                ) : null}
                            </>
                        }
                    />

                    <Pagination
                        currentPage={pagination.current_page}
                        lastPage={pagination.last_page}
                        from={pagination.from}
                        to={pagination.to}
                        total={pagination.total}
                        perPage={pagination.per_page}
                        onPageChange={goToPage}
                        onPerPageChange={setPerPage}
                        label="activity items"
                    />
                </>
            )}

            {emailOpen ? (
                <BulkDocumentsEmailModal
                    documentTypeKey={document_type_key}
                    documentTypeLabel={selectedTypeLabel}
                    employeeIds={effectiveSelectedIds}
                    emailTemplate={email_template}
                    emailIntent="initial"
                    companyName={company_name}
                    previewEmployee={
                        previewEmployee
                            ? {
                                  name: previewEmployee.name,
                                  employee_no: previewEmployee.employee_no,
                                  email: previewEmployee.email,
                              }
                            : null
                    }
                    onOpenChange={setEmailOpen}
                    onSendComplete={clearSelection}
                />
            ) : null}

            <BulkEmailBatchSendsSheet
                batchId={selectedEmailBatchId}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelectedEmailBatchId(null);
                    }
                }}
            />

            <ConfirmDeleteDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Delete selected documents?"
                description={`This will permanently remove ${effectiveDocumentIds.length} document(s) from employee profiles.`}
                onConfirm={handleDelete}
            />

            <DocumentJourneySheet
                open={journeySheetOpen}
                onOpenChange={setJourneySheetOpen}
                identifiers={journeyIdentifiers}
            />
        </Main>
    );
}

function BulkRosterRow({
    employee,
    checked,
    onToggle,
    canDownload,
    onViewJourney,
}: {
    employee: BulkRosterEmployee;
    checked: boolean;
    onToggle: () => void;
    canDownload: boolean;
    onViewJourney: () => void;
}) {
    const hasDocument = employee.document !== null;
    const documentBadge = rosterGenerationBadge(employee);
    const assignment = [employee.department, employee.position]
        .filter(Boolean)
        .join(' · ');
    const process = employee.process;

    return (
        <TableRow
            className={cn(dataTableBodyRowClass(false), 'cursor-pointer')}
            onClick={onViewJourney}
        >
            <TableCell
                className={dataTableCellClass()}
                onClick={(e) => e.stopPropagation()}
            >
                <Checkbox
                    checked={checked}
                    onCheckedChange={onToggle}
                    aria-label={`Select ${employee.name}`}
                />
            </TableCell>
            <TableCell
                className={cn(dataTableCellPrimaryClass(), 'min-w-[200px]')}
            >
                <div className="flex min-w-0 items-center gap-3">
                    <EmployeeProfileLink
                        employeeId={employee.id}
                        stopRowNavigation
                        className="shrink-0"
                    >
                        <EmployeeAvatar
                            name={employee.name}
                            image={employee.image}
                            size="sm"
                        />
                    </EmployeeProfileLink>
                    <div className="min-w-0">
                        <EmployeeProfileLink
                            employeeId={employee.id}
                            className="block truncate text-sm font-semibold text-foreground hover:text-primary"
                            stopRowNavigation
                        >
                            {employee.name}
                        </EmployeeProfileLink>
                        <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                            {employee.employee_no ?? '—'}
                        </p>
                        {assignment ? (
                            <p className="truncate text-[11px] text-muted-foreground/60">
                                {assignment}
                            </p>
                        ) : null}
                    </div>
                </div>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {process ? (
                    process.status === 'generating' ? (
                        <div className="flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-400">
                            <Spinner className="h-3.5 w-3.5" />
                            Generating
                        </div>
                    ) : (
                        <div className="flex flex-col items-start gap-1">
                            <Badge
                                variant="outline"
                                className={cn(
                                    'border font-medium',
                                    badgeToneClasses(process.tone),
                                )}
                            >
                                {process.label}
                            </Badge>
                            {process.action_email?.status === 'failed' ? (
                                <span className="text-[10px] font-medium text-rose-600 dark:text-rose-400">
                                    Action email issue
                                </span>
                            ) : null}
                        </div>
                    )
                ) : documentBadge.kind === 'generating' ? (
                    <div className="flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-400">
                        <Spinner className="h-3.5 w-3.5" />
                        Generating
                    </div>
                ) : documentBadge.kind === 'failed' ? (
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Badge
                                variant="outline"
                                className="border-destructive/30 text-destructive"
                            >
                                Failed
                            </Badge>
                        </TooltipTrigger>
                        <TooltipContent>
                            {employee.generation_error?.message ??
                                'PDF generation failed. Check system logs if the problem continues.'}
                        </TooltipContent>
                    </Tooltip>
                ) : (
                    <Badge
                        variant={
                            documentBadge.kind === 'generated'
                                ? 'secondary'
                                : 'outline'
                        }
                        className={cn(
                            documentBadge.kind === 'generated'
                                ? 'border-0 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                : documentBadge.kind === 'queued'
                                  ? 'border-dashed text-amber-700 dark:text-amber-400'
                                  : 'border-dashed text-muted-foreground/70',
                        )}
                    >
                        {documentBadge.label}
                    </Badge>
                )}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {process?.waiting_for ? (
                    <span className="text-xs font-medium text-foreground">
                        {process.waiting_for}
                    </span>
                ) : (
                    <span className="text-muted-foreground/60">—</span>
                )}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {process?.last_activity ? (
                    <div className="flex max-w-[200px] flex-col text-xs">
                        <span
                            className="truncate text-foreground"
                            title={process.last_activity.event}
                        >
                            {process.last_activity.event}
                        </span>
                        {process.last_activity.relative ? (
                            <span className="text-[11px] text-muted-foreground/70">
                                {process.last_activity.relative}
                            </span>
                        ) : process.last_activity.timestamp ? (
                            <span className="text-[11px] text-muted-foreground/70">
                                {formatDisplayDateTime12h(
                                    process.last_activity.timestamp,
                                )}
                            </span>
                        ) : null}
                    </div>
                ) : (
                    <span className="text-muted-foreground/60">—</span>
                )}
            </TableCell>
            <TableCell
                className={dataTableActionsCellClass()}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-end gap-1.5">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onViewJourney}
                        title="View document journey"
                        aria-label={`View document journey for ${employee.name}`}
                    >
                        <Eye className="mr-1.5 h-4 w-4" />
                        View
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        asChild
                    >
                        <Link
                            href={documentRoutes.employee.url({
                                employee: employee.id,
                            })}
                            title="View document folder"
                            aria-label={`View document folder for ${employee.name}`}
                        >
                            <Folder className="h-4 w-4" />
                        </Link>
                    </Button>
                    {hasDocument && canDownload ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-8 w-8"
                            asChild
                        >
                            <a
                                href={`/organization/documents/files/${employee.document!.id}/download`}
                                target="_blank"
                                rel="noreferrer"
                                title="Download document"
                                aria-label={`Download document for ${employee.name}`}
                            >
                                <Download className="h-4 w-4" />
                            </a>
                        </Button>
                    ) : null}
                </div>
            </TableCell>
        </TableRow>
    );
}
