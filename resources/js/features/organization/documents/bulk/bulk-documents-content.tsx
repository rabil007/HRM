import { Link, router, usePoll } from '@inertiajs/react';
import {
    Download,
    FileStack,
    Folder,
    FolderTree,
    Loader2,
    Mail,
    RotateCcw,
    Trash2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { BulkDocumentsHistoryTable } from '@/features/organization/documents/bulk/bulk-documents-history-table';
import { BulkEmailBatchSendsSheet } from '@/features/organization/documents/bulk/bulk-email-batch-sends-sheet';
import {
    BulkDocumentsViewSwitcher
    
} from '@/features/organization/documents/bulk/bulk-documents-view-switcher';
import type {BulkDocumentsView} from '@/features/organization/documents/bulk/bulk-documents-view-switcher';
import { BulkDocumentsEmailModal } from '@/features/organization/documents/bulk/bulk-email-modal';
import { BulkSignaturesTable } from '@/features/organization/documents/bulk/bulk-signatures-table';
import { SignatureStatusBadge } from '@/features/organization/documents/bulk/signature-status-badge';
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';
import { downloadBulkZip } from '@/features/organization/documents/shared/download-bulk-zip';
import { useBulkSelection } from '@/features/organization/documents/shared/use-bulk-selection';
import { DepartmentEmployeeTree } from '@/features/organization/employees/components/department-employee-tree';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';
import {
    EMPTY_BULK_DOCUMENT_FILTERS
} from './types';
import type {BulkDocumentFilters, BulkDocumentsPageProps, BulkEmailFilter, BulkGenerationFilter, BulkRosterEmployee, BulkSignatureFilter, LatestEmailBatch} from './types';

const BULK_URL = '/organization/documents/bulk';

function buildQuery(
    documentTypeKey: string,
    filters: BulkDocumentFilters,
    search: string,
    generationFilter: BulkGenerationFilter,
    view: BulkDocumentsView,
    signatureFilter: BulkSignatureFilter = 'all',
    emailFilter: BulkEmailFilter = 'all',
    pagination?: { page?: number | null; perPage: number },
): Record<string, string> {
    const query: Record<string, string> = {
        document_type_key: documentTypeKey,
        per_page: String(pagination?.perPage ?? 20),
    };

    if (view === 'history') {
        query.view = 'history';
    }

    if (view === 'signatures') {
        query.view = 'signatures';
    }

    if (signatureFilter !== 'all') {
        query.signature_filter = signatureFilter;
    }

    if (search.trim()) {
        query.search = search.trim();
    }

    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            query[key] = value;
        }
    });

    if (generationFilter === 'missing' || generationFilter === 'generated') {
        query.generation_filter = generationFilter;
    }

    if (emailFilter === 'emailed' || emailFilter === 'not_emailed') {
        query.email_filter = emailFilter;
    }

    if (pagination?.page) {
        query.page = String(pagination.page);
    }

    return query;
}

function SummaryCard({
    label,
    value,
    active,
    onClick,
    cardClass,
    activeClass,
    valueClass,
}: {
    label: string;
    value: number;
    active: boolean;
    onClick: () => void;
    cardClass: string;
    activeClass: string;
    valueClass: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className="rounded-xl text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
        >
            <Card
                className={cn(
                    'glass-card cursor-pointer transition-all duration-200',
                    cardClass,
                    active && activeClass,
                )}
            >
                <CardContent className="p-4">
                    <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/80 uppercase">
                        {label}
                    </p>
                    <p
                        className={cn(
                            'mt-1 text-2xl font-bold tabular-nums',
                            valueClass,
                        )}
                    >
                        {value}
                    </p>
                </CardContent>
            </Card>
        </button>
    );
}

function ProgressBanner({
    latestRun,
}: {
    latestRun: BulkDocumentsPageProps['latest_run'];
}) {
    const [dismissedRunId, setDismissedRunId] = useState<number | null>(null);

    const status = latestRun?.status;
    const isRunning = status === 'running' || status === 'queued';
    const isFailed = status === 'failed';
    const isCompleted = status === 'completed';

    // Auto-hide the completed banner shortly after it appears; the toast
    // already confirms completion, so it does not need to linger.
    useEffect(() => {
        if (!latestRun || !isCompleted) {
            return;
        }

        const runId = latestRun.id;
        const timeout = window.setTimeout(() => {
            setDismissedRunId(runId);
        }, 6000);

        return () => window.clearTimeout(timeout);
    }, [isCompleted, latestRun]);

    if (!latestRun) {
        return null;
    }

    if (!isRunning && !isFailed && !isCompleted) {
        return null;
    }

    // Running runs are always shown; completed/failed can be dismissed.
    if (!isRunning && dismissedRunId === latestRun.id) {
        return null;
    }

    const processed =
        latestRun.generated_count +
        latestRun.replaced_count +
        latestRun.skipped_count +
        latestRun.failed_count;

    let message = '';

    if (isRunning) {
        message = `Generating… ${processed} of ${latestRun.total_targeted} processed`;
    } else if (isCompleted) {
        const parts = [`${latestRun.generated_count} created`];

        if (latestRun.replaced_count > 0) {
            parts.push(`${latestRun.replaced_count} updated`);
        }

        if (latestRun.skipped_count > 0) {
            parts.push(`${latestRun.skipped_count} skipped`);
        }

        message = parts.join(' · ');
    } else {
        message = 'Document generation failed. Please try again.';
    }

    return (
        <div
            className={cn(
                'mb-6 flex items-center gap-3 rounded-xl border px-4 py-3 text-sm',
                isRunning &&
                    'border-amber-500/25 bg-amber-500/6 text-amber-700 dark:text-amber-400',
                isCompleted &&
                    'border-emerald-500/25 bg-emerald-500/6 text-emerald-700 dark:text-emerald-400',
                isFailed &&
                    'border-destructive/25 bg-destructive/6 text-destructive',
            )}
        >
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
                    onClick={() => setDismissedRunId(latestRun.id)}
                    aria-label="Dismiss"
                >
                    <X className="h-3.5 w-3.5" />
                </Button>
            ) : null}
        </div>
    );
}

function EmailProgressBanner({
    latestEmailBatch,
}: {
    latestEmailBatch: LatestEmailBatch | null;
}) {
    const [dismissedBatchId, setDismissedBatchId] = useState<number | null>(null);

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
            parts.push(`${latestEmailBatch.skipped_no_email_count} skipped (no email)`);
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
    view,
    filters: initialFilters,
    search: initialSearch,
    counts,
    employees,
    signature_requests,
    activity,
    pagination,
    generation_filter,
    email_filter,
    signature_filter,
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
    const isSignaturesView = view === 'signatures';
    const isHistoryView = view === 'history';
    const showEmployeeFilters =
        isRosterView || isSignaturesView || isHistoryView;
    const supportsEsignature = document_type_key === 'salary_declaration';
    const [searchInput, setSearchInput] = useState(initialSearch);
    const [filters, setFilters] = useState<BulkDocumentFilters>({
        department_id: initialFilters.department_id,
        position_id: initialFilters.position_id,
        company_visa_type_id: initialFilters.company_visa_type_id,
    });
    const [deptPopoverOpen, setDeptPopoverOpen] = useState(false);
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
    } = useBulkSelection(employeeIds);

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

    const effectiveSelectedIds =
        matchingSelection?.employee_ids ?? selectedIds;
    const effectiveSelectedCount =
        matchingSelection?.total ?? selectedCount;
    const effectiveDocumentIds =
        matchingSelection?.document_ids ?? selectedDocumentIds;

    const clearSelection = useCallback(() => {
        clear();
        setMatchingSelection(null);
    }, [clear]);

    useEffect(() => {
        setMatchingSelection(null);
    }, [document_type_key, filters, generation_filter, searchInput]);

    const previewEmployee = useMemo(() => {
        if (matchingSelection) {
            return employees[0] ?? null;
        }

        return selectedEmployees[0] ?? null;
    }, [employees, matchingSelection, selectedEmployees]);

    const selectedTypeLabel =
        document_type_options.find((option) => option.value === document_type_key)
            ?.label ?? document_type_key;

    const missingCount = counts.not_generated;
    const generateLabel =
        effectiveSelectedCount > 0
            ? `Generate for ${effectiveSelectedCount} selected`
            : `Generate missing (${missingCount})`;

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
                    generation_filter,
                    'roster',
                    signature_filter,
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

    const isRunActive =
        latest_run?.status === 'running' || latest_run?.status === 'queued';

    const isEmailBatchActive =
        latest_email_batch?.status === 'running' ||
        latest_email_batch?.status === 'queued';

    const { start, stop } = usePoll(
        3000,
        {
            only: [
                'latest_run',
                'latest_email_batch',
                'counts',
                'employees',
                'activity',
                'pagination',
                'flash',
            ],
            preserveScroll: true,
        },
        { autoStart: false },
    );

    useEffect(() => {
        if ((!isRunActive && !isEmailBatchActive) || !isRosterView) {
            stop();

            return;
        }

        start();

        return () => {
            stop();
        };
    }, [isRunActive, isEmailBatchActive, isRosterView, start, stop]);

    const previousRunStatus = useRef(latest_run?.status);
    useEffect(() => {
        const previous = previousRunStatus.current;

        if (
            (previous === 'running' || previous === 'queued') &&
            latest_run?.status === 'completed'
        ) {
            toast.success('Document generation completed.');
        }

        if (
            (previous === 'running' || previous === 'queued') &&
            latest_run?.status === 'failed'
        ) {
            toast.error('Document generation failed.');
        }

        previousRunStatus.current = latest_run?.status;
    }, [latest_run?.status]);

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
            nextGenerationFilter = generation_filter,
            nextView: BulkDocumentsView = view,
            nextSignatureFilter: BulkSignatureFilter = signature_filter,
            nextEmailFilter: BulkEmailFilter = email_filter,
            page: number | null = null,
        ) => {
            router.get(
                BULK_URL,
                buildQuery(
                    nextType,
                    nextFilters,
                    nextSearch,
                    nextGenerationFilter,
                    nextView,
                    nextSignatureFilter,
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
            generation_filter,
            signature_filter,
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
                generation_filter,
                nextView,
                signature_filter,
                email_filter,
                null,
            );
        },
        [
            clearSelection,
            document_type_key,
            email_filter,
            filters,
            generation_filter,
            signature_filter,
            navigate,
            searchInput,
        ],
    );

    const openSignaturesReview = useCallback(() => {
        navigate(
            document_type_key,
            filters,
            searchInput,
            generation_filter,
            'signatures',
            'submitted',
            email_filter,
            null,
        );
    }, [
        document_type_key,
        email_filter,
        filters,
        generation_filter,
        navigate,
        searchInput,
    ]);

    const goToPage = useCallback(
        (page: number) => {
            navigate(
                document_type_key,
                filters,
                searchInput,
                generation_filter,
                view,
                signature_filter,
                email_filter,
                page,
            );
        },
        [
            document_type_key,
            email_filter,
            filters,
            generation_filter,
            navigate,
            searchInput,
            signature_filter,
            view,
        ],
    );

    const setPerPage = useCallback(
        (perPage: number) => {
            router.get(
                BULK_URL,
                buildQuery(
                    document_type_key,
                    filters,
                    searchInput,
                    generation_filter,
                    view,
                    signature_filter,
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
            generation_filter,
            searchInput,
            signature_filter,
            view,
        ],
    );

    useEffect(() => {
        if (!showEmployeeFilters) {
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
        navigate,
        searchInput,
        showEmployeeFilters,
    ]);

    const handleGenerate = () => {
        if (!can.generate || isGenerating) {
            return;
        }

        setIsGenerating(true);

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

    const deptSelectionCount =
        filters.department_id || filters.position_id ? 1 : 0;

    const setGenerationFilter = useCallback(
        (next: BulkGenerationFilter) => {
            navigate(
                document_type_key,
                filters,
                searchInput,
                next,
            );
        },
        [document_type_key, filters, navigate, searchInput],
    );

    const setEmailFilter = useCallback(
        (next: BulkEmailFilter) => {
            navigate(
                document_type_key,
                filters,
                searchInput,
                generation_filter,
                view,
                signature_filter,
                next,
            );
        },
        [
            document_type_key,
            filters,
            generation_filter,
            navigate,
            searchInput,
            signature_filter,
            view,
        ],
    );

    const employeeFilterCount = [
        filters.department_id,
        filters.position_id,
        searchInput.trim(),
        email_filter !== 'all',
    ].filter(Boolean).length;

    const activeFilterCount = isRosterView
        ? employeeFilterCount + (generation_filter !== 'all' ? 1 : 0)
        : employeeFilterCount;

    const clearAllFilters = useCallback(() => {
        const nextFilters = { ...EMPTY_BULK_DOCUMENT_FILTERS };

        setFilters(nextFilters);
        setSearchInput('');
        navigate(
            document_type_key,
            nextFilters,
            '',
            'all',
            view,
            signature_filter,
            'all',
        );
    }, [document_type_key, navigate, signature_filter, view]);

    return (
        <Main>
            <PageHeader
                title="Bulk generate"
                description={`Generate and manage ${selectedTypeLabel} documents for multiple employees.`}
                right={
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <AppSelect
                            value={document_type_key}
                            onValueChange={(value) => navigate(value)}
                            className="h-12 w-full rounded-xl sm:w-56"
                        >
                            {document_type_options.map((option) => (
                                <AppSelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>

                        {isRosterView && can.generate && effectiveSelectedCount === 0 ? (
                            <Button
                                type="button"
                                onClick={handleGenerate}
                                disabled={
                                    isGenerating ||
                                    missingCount === 0 ||
                                    isRunActive
                                }
                                className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20"
                            >
                                {isGenerating ? (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                ) : (
                                    <FileStack className="mr-2 h-4 w-4" />
                                )}
                                {generateLabel}
                            </Button>
                        ) : null}
                    </div>
                }
            />

            {/* Summary cards */}
            {isRosterView && supportsEsignature ? (
                <div className="mb-8 grid gap-4 sm:grid-cols-4">
                    <SummaryCard
                        label="In this list"
                        value={counts.targeted}
                        active={generation_filter === 'all'}
                        onClick={() => setGenerationFilter('all')}
                        cardClass="border-border bg-muted/20 hover:border-border dark:border-white/10 dark:hover:border-white/20"
                        activeClass="border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10"
                        valueClass="text-foreground"
                    />
                    <SummaryCard
                        label="Already generated"
                        value={counts.generated}
                        active={generation_filter === 'generated'}
                        onClick={() => setGenerationFilter('generated')}
                        cardClass="border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30"
                        activeClass="border-emerald-500/40 ring-1 ring-emerald-500/25"
                        valueClass="text-emerald-500 dark:text-emerald-400"
                    />
                    <SummaryCard
                        label="Missing document"
                        value={counts.not_generated}
                        active={generation_filter === 'missing'}
                        onClick={() => setGenerationFilter('missing')}
                        cardClass="border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30"
                        activeClass="border-amber-500/40 ring-1 ring-amber-500/25"
                        valueClass="text-amber-500 dark:text-amber-400"
                    />
                    <SummaryCard
                        label="Pending review"
                        value={counts.pending_review}
                        active={false}
                        onClick={openSignaturesReview}
                        cardClass="border-violet-500/15 bg-violet-500/[0.04] hover:border-violet-500/30"
                        activeClass="border-violet-500/40 ring-1 ring-violet-500/25"
                        valueClass="text-violet-600 dark:text-violet-400"
                    />
                </div>
            ) : isRosterView ? (
                <div className="mb-8 grid gap-4 sm:grid-cols-3">
                    <SummaryCard
                        label="In this list"
                        value={counts.targeted}
                        active={generation_filter === 'all'}
                        onClick={() => setGenerationFilter('all')}
                        cardClass="border-border bg-muted/20 hover:border-border dark:border-white/10 dark:hover:border-white/20"
                        activeClass="border-primary/30 ring-1 ring-primary/10 dark:border-white/20 dark:ring-white/10"
                        valueClass="text-foreground"
                    />
                    <SummaryCard
                        label="Already generated"
                        value={counts.generated}
                        active={generation_filter === 'generated'}
                        onClick={() => setGenerationFilter('generated')}
                        cardClass="border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30"
                        activeClass="border-emerald-500/40 ring-1 ring-emerald-500/25"
                        valueClass="text-emerald-500 dark:text-emerald-400"
                    />
                    <SummaryCard
                        label="Missing document"
                        value={counts.not_generated}
                        active={generation_filter === 'missing'}
                        onClick={() => setGenerationFilter('missing')}
                        cardClass="border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30"
                        activeClass="border-amber-500/40 ring-1 ring-amber-500/25"
                        valueClass="text-amber-500 dark:text-amber-400"
                    />
                </div>
            ) : null}

            {/* Search / view controls */}
            {showEmployeeFilters ? (
                <div className="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center">
                    <SearchBar
                        placeholder="Search employees by name or employee no…"
                        value={searchInput}
                        onChange={setSearchInput}
                        className="mb-0 flex-1"
                    />

                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {/* Desktop: Departments popover */}
                        <Popover
                            open={deptPopoverOpen}
                            onOpenChange={setDeptPopoverOpen}
                        >
                            <PopoverTrigger asChild>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="hidden h-12 rounded-xl glass-card px-5 hover:bg-accent lg:flex"
                                >
                                    <FolderTree className="mr-2 h-4 w-4" />
                                    Departments
                                    {deptSelectionCount ? (
                                        <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                            {deptSelectionCount}
                                        </span>
                                    ) : null}
                                </Button>
                            </PopoverTrigger>
                            <PopoverContent
                                align="start"
                                className="w-72 glass-card border-border p-3 dark:border-white/6"
                            >
                                <DepartmentEmployeeTree
                                    nodes={department_tree}
                                    selectedDepartmentId={
                                        department_tree_selected_id
                                    }
                                    selectedPositionId={
                                        department_tree_selected_position_id
                                    }
                                    onSelectDepartment={(departmentId) => {
                                        const next = {
                                            ...filters,
                                            department_id: departmentId
                                                ? String(departmentId)
                                                : '',
                                            position_id: '',
                                        };
                                        setFilters(next);
                                        navigate(
                                            document_type_key,
                                            next,
                                            searchInput,
                                        );
                                        setDeptPopoverOpen(false);
                                    }}
                                    onSelectPosition={(
                                        positionId,
                                        departmentId,
                                    ) => {
                                        const next = {
                                            ...filters,
                                            department_id:
                                                String(departmentId),
                                            position_id: String(positionId),
                                        };
                                        setFilters(next);
                                        navigate(
                                            document_type_key,
                                            next,
                                            searchInput,
                                        );
                                        setDeptPopoverOpen(false);
                                    }}
                                />
                            </PopoverContent>
                        </Popover>

                        <AppSelect
                            value={email_filter}
                            onValueChange={(value) =>
                                setEmailFilter(value as BulkEmailFilter)
                            }
                            className="h-12 w-full rounded-xl glass-card sm:w-56"
                        >
                            <AppSelectItem value="all">
                                All email status
                            </AppSelectItem>
                            <AppSelectItem value="emailed">Emailed</AppSelectItem>
                            <AppSelectItem value="not_emailed">
                                Not emailed
                            </AppSelectItem>
                        </AppSelect>

                        {activeFilterCount > 0 ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="h-12 rounded-xl glass-card px-4 hover:bg-accent"
                                onClick={clearAllFilters}
                            >
                                <X className="mr-2 h-4 w-4" />
                                Clear all
                            </Button>
                        ) : null}
                    </div>
                </div>
            ) : null}

            {/* Active filter chips */}
            {showEmployeeFilters && activeFilterCount > 0 ? (
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <span className="text-xs font-medium text-muted-foreground/80">
                        Active filters
                    </span>

                    {filters.department_id || filters.position_id ? (
                        <Badge
                            variant="outline"
                            className="gap-1 pr-1 pl-2.5 font-normal"
                        >
                            {filters.position_id
                                ? 'Department · position'
                                : 'Department'}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-muted"
                                onClick={() => {
                                    const next = {
                                        ...filters,
                                        department_id: '',
                                        position_id: '',
                                    };
                                    setFilters(next);
                                    navigate(
                                        document_type_key,
                                        next,
                                        searchInput,
                                    );
                                }}
                                aria-label="Clear department filter"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    {searchInput.trim() ? (
                        <Badge
                            variant="outline"
                            className="gap-1 pr-1 pl-2.5 font-normal"
                        >
                            Search: {searchInput.trim()}
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-muted"
                                onClick={() => {
                                    setSearchInput('');
                                    navigate(document_type_key, filters, '');
                                }}
                                aria-label="Clear search"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    {email_filter === 'emailed' ? (
                        <Badge
                            variant="outline"
                            className="gap-1 border-sky-500/25 bg-sky-500/5 pr-1 pl-2.5 font-normal"
                        >
                            Emailed only
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-sky-500/10"
                                onClick={() => setEmailFilter('all')}
                                aria-label="Clear emailed filter"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    {email_filter === 'not_emailed' ? (
                        <Badge
                            variant="outline"
                            className="gap-1 border-dashed pr-1 pl-2.5 font-normal"
                        >
                            Not emailed only
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-muted"
                                onClick={() => setEmailFilter('all')}
                                aria-label="Clear not emailed filter"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    {isRosterView && generation_filter === 'generated' ? (
                        <Badge
                            variant="outline"
                            className="gap-1 border-emerald-500/25 bg-emerald-500/5 pr-1 pl-2.5 font-normal"
                        >
                            Already generated only
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-emerald-500/10"
                                onClick={() => setGenerationFilter('all')}
                                aria-label="Clear generated filter"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    {isRosterView && generation_filter === 'missing' ? (
                        <Badge
                            variant="outline"
                            className="gap-1 border-amber-500/25 bg-amber-500/5 pr-1 pl-2.5 font-normal"
                        >
                            Missing document only
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-5 w-5 rounded-full hover:bg-amber-500/10"
                                onClick={() => setGenerationFilter('all')}
                                aria-label="Clear missing document filter"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </Badge>
                    ) : null}

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
                        onClick={clearAllFilters}
                    >
                        Clear all
                    </Button>
                </div>
            ) : null}

            {isRosterView ? <ProgressBanner latestRun={latest_run} /> : null}
            {isRosterView ? (
                <EmailProgressBanner latestEmailBatch={latest_email_batch} />
            ) : null}

            {isRosterView ? (
                <>
            {/* Selection toolbar */}
            <DocumentsBulkToolbar
                count={effectiveSelectedCount}
                itemLabel="employees"
                onClear={clearSelection}
                selectAllMatching={
                    showSelectAllMatching
                        ? {
                              total: pagination.total,
                              onSelect: () => void handleSelectAllMatching(),
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
                                {isGenerating ? (
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
                        {can.email ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setEmailOpen(true)}
                            >
                                <Mail className="mr-2 h-3.5 w-3.5" />
                                Send email
                            </Button>
                        ) : null}
                        {can.delete ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="text-destructive hover:text-destructive"
                                onClick={() => setDeleteOpen(true)}
                                disabled={effectiveDocumentIds.length === 0}
                            >
                                <Trash2 className="mr-2 h-3.5 w-3.5" />
                                Delete
                            </Button>
                        ) : null}
                    </>
                }
            />

            {effectiveSelectedCount > 0 ? (
                <p className="mb-3 flex items-center gap-1.5 text-xs text-muted-foreground/80">
                    <RotateCcw className="h-3 w-3" />
                    Existing documents for selected employees will be replaced.
                </p>
            ) : null}

            {/* Employee table */}
            <OrganizationDataTable
                minWidth="min-w-[880px]"
                header={
                    <>
                        <span />
                        <BulkDocumentsViewSwitcher
                            value={view}
                            onChange={setView}
                            showSignatures={supportsEsignature}
                        />
                    </>
                }
            >
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead className="w-10">
                            <Checkbox
                                checked={isHeaderCheckboxChecked}
                                onCheckedChange={handleToggleAllEmployees}
                                aria-label="Select all employees"
                            />
                        </DataTableHead>
                        <DataTableHead>Employee</DataTableHead>
                        <DataTableHead>Email</DataTableHead>
                        <DataTableHead>Emailed</DataTableHead>
                        <DataTableHead>Document</DataTableHead>
                        {supportsEsignature ? (
                            <DataTableHead>Signature</DataTableHead>
                        ) : null}
                        <DataTableHead className="text-right">
                            Actions
                        </DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {employees.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={supportsEsignature ? 7 : 6} className="p-0">
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
                                checked={isEmployeeRowSelected(employee.id)}
                                onToggle={() => handleToggleEmployee(employee.id)}
                                canDownload={can.download}
                                showSignature={supportsEsignature}
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
            ) : isSignaturesView ? (
                <>
                    <BulkSignaturesTable
                        requests={signature_requests}
                        canReview={can.review_signatures}
                        header={
                            <>
                                <span className="text-sm text-muted-foreground/80">
                                    Review employee signature submissions for{' '}
                                    {selectedTypeLabel}.
                                </span>
                                <BulkDocumentsViewSwitcher
                                    value={view}
                                    onChange={setView}
                                    showSignatures={supportsEsignature}
                                />
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
                        label="signature requests"
                    />
                </>
            ) : (
                <>
                    <BulkDocumentsHistoryTable
                        activity={activity}
                        onEmailBatchClick={setSelectedEmailBatchId}
                        header={
                            <>
                                <span className="text-sm text-muted-foreground/80">
                                    All bulk generation and email runs for{' '}
                                    {selectedTypeLabel}.
                                </span>
                                <BulkDocumentsViewSwitcher
                                    value={view}
                                    onChange={setView}
                                    showSignatures={supportsEsignature}
                                />
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
        </Main>
    );
}

function BulkRosterRow({
    employee,
    checked,
    onToggle,
    canDownload,
    showSignature = false,
}: {
    employee: BulkRosterEmployee;
    checked: boolean;
    onToggle: () => void;
    canDownload: boolean;
    showSignature?: boolean;
}) {
    const hasDocument = employee.document !== null;
    const assignment = [employee.department, employee.position]
        .filter(Boolean)
        .join(' · ');

    return (
        <TableRow className={dataTableBodyRowClass(false)}>
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
            <TableCell className={dataTableCellPrimaryClass()}>
                <div className="flex items-center gap-3">
                    <EmployeeAvatar
                        name={employee.name}
                        image={employee.image}
                        size="sm"
                    />
                    <div className="min-w-0">
                        <EmployeeProfileLink
                            employeeId={employee.id}
                            stopRowNavigation
                            className="truncate"
                        >
                            {employee.name}
                        </EmployeeProfileLink>
                        <div className="text-xs text-muted-foreground/70">
                            {employee.employee_no ?? '—'}
                        </div>
                        {assignment ? (
                            <div className="text-xs text-muted-foreground/70">
                                {assignment}
                            </div>
                        ) : null}
                    </div>
                </div>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {employee.email ? (
                    <a
                        href={`mailto:${employee.email}`}
                        className="text-sm text-primary hover:underline"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {employee.email}
                    </a>
                ) : (
                    <span className="text-muted-foreground/70">—</span>
                )}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {employee.email_sent_at ? (
                    <div className="flex flex-col gap-0.5">
                        <Badge className="w-fit border-0 bg-sky-500/10 text-sky-600 dark:text-sky-400">
                            Sent
                        </Badge>
                        <span className="text-[11px] text-muted-foreground/70">
                            {formatDisplayDateTime12h(employee.email_sent_at)}
                        </span>
                    </div>
                ) : (
                    <Badge
                        variant="outline"
                        className="border-dashed text-muted-foreground/60"
                    >
                        Not emailed
                    </Badge>
                )}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <Badge
                    variant={hasDocument ? 'secondary' : 'outline'}
                    className={cn(
                        hasDocument
                            ? 'border-0 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                            : 'border-dashed text-muted-foreground/70',
                    )}
                >
                    {hasDocument ? 'Generated' : 'Missing'}
                </Badge>
            </TableCell>
            {showSignature ? (
                <TableCell className={dataTableCellClass()}>
                    <SignatureStatusBadge
                        status={employee.signature_status}
                    />
                </TableCell>
            ) : null}
            <TableCell
                className={dataTableActionsCellClass()}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-end gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        asChild
                    >
                        <Link
                            href={documents.employee.url({
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
