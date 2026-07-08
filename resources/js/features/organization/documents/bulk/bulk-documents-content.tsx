import { Link, router, usePoll } from '@inertiajs/react';
import {
    Download,
    FileStack,
    Filter,
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
import { DepartmentEmployeeTree } from '@/features/organization/employees/components/department-employee-tree';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import {
    BulkDocumentsFiltersSheet,
    EMPTY_BULK_DOCUMENT_FILTERS,
} from '@/features/organization/documents/bulk/bulk-documents-filters-sheet';
import type { BulkDocumentFilters } from '@/features/organization/documents/bulk/bulk-documents-filters-sheet';
import { BulkDocumentsEmailModal } from '@/features/organization/documents/bulk/bulk-email-modal';
import { BulkDocumentsHistoryTable } from '@/features/organization/documents/bulk/bulk-documents-history-table';
import {
    BulkDocumentsViewSwitcher,
    type BulkDocumentsView,
} from '@/features/organization/documents/bulk/bulk-documents-view-switcher';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';
import { downloadBulkZip } from '@/features/organization/documents/shared/download-bulk-zip';
import { useBulkSelection } from '@/features/organization/documents/shared/use-bulk-selection';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';
import type {
    BulkDocumentsPageProps,
    BulkRosterEmployee,
} from './types';

const BULK_URL = '/organization/documents/bulk';

function buildQuery(
    documentTypeKey: string,
    filters: BulkDocumentFilters,
    search: string,
    generationFilter: string,
    view: BulkDocumentsView,
    pagination?: { page?: number | null; perPage: number },
): Record<string, string> {
    const query: Record<string, string> = {
        document_type_key: documentTypeKey,
        per_page: String(pagination?.perPage ?? 20),
    };

    if (view === 'history') {
        query.view = 'history';
    }

    if (search.trim()) {
        query.search = search.trim();
    }

    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            query[key] = value;
        }
    });

    if (generationFilter === 'missing') {
        query.generation_filter = 'missing';
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
    active?: boolean;
    onClick?: () => void;
    cardClass?: string;
    activeClass?: string;
    valueClass?: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'text-left',
                onClick ? 'cursor-pointer' : 'cursor-default',
            )}
        >
            <Card
                className={cn(
                    'transition-all duration-200',
                    cardClass,
                    active && activeClass,
                )}
            >
                <CardContent className="p-5">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground/80 uppercase">
                        {label}
                    </p>
                    <p
                        className={cn(
                            'mt-2 text-3xl font-extrabold tabular-nums',
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

export function BulkDocumentsContent({
    document_type_key,
    document_type_options,
    view,
    filters: initialFilters,
    search: initialSearch,
    counts,
    employees,
    activity,
    pagination,
    generation_filter,
    positions,
    company_visa_types,
    department_tree,
    department_tree_selected_id,
    department_tree_selected_position_id,
    email_templates,
    latest_run,
    can,
}: BulkDocumentsPageProps) {
    const isRosterView = view === 'roster';
    const [searchInput, setSearchInput] = useState(initialSearch);
    const [filters, setFilters] = useState<BulkDocumentFilters>({
        department_id: initialFilters.department_id,
        position_id: initialFilters.position_id,
        status: initialFilters.status || 'active',
        company_visa_type_id: initialFilters.company_visa_type_id,
    });
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [deptPopoverOpen, setDeptPopoverOpen] = useState(false);
    const [emailOpen, setEmailOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [isGenerating, setIsGenerating] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [isDownloading, setIsDownloading] = useState(false);

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

    const selectedTypeLabel =
        document_type_options.find((option) => option.value === document_type_key)
            ?.label ?? document_type_key;

    const missingCount = counts.not_generated;
    const generateLabel =
        selectedCount > 0
            ? `Generate for ${selectedCount} selected`
            : `Generate missing (${missingCount})`;

    const isRunActive =
        latest_run?.status === 'running' || latest_run?.status === 'queued';

    const { start, stop } = usePoll(
        3000,
        {
            only: [
                'latest_run',
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
        if (!isRunActive || !isRosterView) {
            stop();
            return;
        }

        start();

        return () => {
            stop();
        };
    }, [isRunActive, isRosterView, start, stop]);

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

    const navigate = useCallback(
        (
            nextType = document_type_key,
            nextFilters = filters,
            nextSearch = searchInput,
            nextGenerationFilter = generation_filter,
            nextView: BulkDocumentsView = view,
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
            filters,
            generation_filter,
            pagination.per_page,
            searchInput,
            view,
        ],
    );

    const setView = useCallback(
        (nextView: BulkDocumentsView) => {
            clear();
            navigate(
                document_type_key,
                filters,
                searchInput,
                generation_filter,
                nextView,
                null,
            );
        },
        [
            clear,
            document_type_key,
            filters,
            generation_filter,
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
                generation_filter,
                view,
                page,
            );
        },
        [document_type_key, filters, generation_filter, navigate, searchInput, view],
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
                    { perPage },
                ),
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [
            document_type_key,
            filters,
            generation_filter,
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
        if (!can.generate || isGenerating) {
            return;
        }

        setIsGenerating(true);

        router.post(
            '/organization/documents/bulk/generate',
            {
                document_type_key,
                ...filters,
                search: searchInput,
                ...(selectedCount > 0 ? { employee_ids: selectedIds } : {}),
            },
            {
                preserveScroll: true,
                onFinish: () => setIsGenerating(false),
            },
        );
    };

    const handleDelete = () => {
        if (!can.delete || isDeleting || selectedDocumentIds.length === 0) {
            return;
        }

        setIsDeleting(true);

        router.delete('/organization/documents/bulk/documents', {
            data: {
                document_type_key,
                document_ids: selectedDocumentIds,
            },
            preserveScroll: true,
            onSuccess: () => {
                setDeleteOpen(false);
                clear();
            },
            onFinish: () => setIsDeleting(false),
        });
    };

    const handleDownload = async () => {
        if (!can.download || isDownloading) {
            return;
        }

        const withDocuments = selectedEmployees.filter(
            (employee) => employee.document !== null,
        );

        if (withDocuments.length === 0) {
            toast.error('No generated documents in the current selection.');
            return;
        }

        setIsDownloading(true);

        try {
            await downloadBulkZip('/organization/documents/bulk/download', {
                document_type_key,
                employee_ids: withDocuments.map((employee) => employee.id),
            });
        } catch (error) {
            toast.error(
                error instanceof Error ? error.message : 'Download failed.',
            );
        } finally {
            setIsDownloading(false);
        }
    };

    const activeFiltersCount = [
        filters.position_id,
        filters.company_visa_type_id,
        filters.status && filters.status !== 'active' ? filters.status : '',
    ].filter(Boolean).length;

    const deptSelectionCount =
        filters.department_id || filters.position_id ? 1 : 0;

    return (
        <Main>
            <DocumentsBreadcrumbs
                items={[
                    { title: 'Documents', href: documents.url() },
                    { title: 'Bulk generate' },
                ]}
            />

            <PageHeader
                title="Bulk generate"
                description={`Generate and manage ${selectedTypeLabel} documents for multiple employees.`}
                right={
                    isRosterView && can.generate && selectedCount === 0 ? (
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
                    ) : null
                }
            />

            {/* Document type selector */}
            <div className="mb-6">
                <AppSelect
                    value={document_type_key}
                    onValueChange={(value) => navigate(value)}
                    className="w-full sm:w-72"
                >
                    {document_type_options.map((option) => (
                        <AppSelectItem key={option.value} value={option.value}>
                            {option.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>
            </div>

            {/* Summary cards */}
            {isRosterView ? (
                <div className="mb-8 grid gap-4 sm:grid-cols-3">
                <SummaryCard
                    label="In this list"
                    value={counts.targeted}
                    cardClass="border-border bg-muted/20 hover:border-border dark:border-white/10 dark:hover:border-white/20"
                    activeClass="border-primary/30 ring-1 ring-primary/10"
                    valueClass="text-foreground"
                />
                <SummaryCard
                    label="Already generated"
                    value={counts.generated}
                    cardClass="border-emerald-500/15 bg-emerald-500/[0.04] hover:border-emerald-500/30"
                    activeClass="border-emerald-500/40 ring-1 ring-emerald-500/25"
                    valueClass="text-emerald-500 dark:text-emerald-400"
                />
                <SummaryCard
                    label="Missing document"
                    value={counts.not_generated}
                    active={generation_filter === 'missing'}
                    onClick={() =>
                        navigate(
                            document_type_key,
                            filters,
                            searchInput,
                            generation_filter === 'missing' ? 'all' : 'missing',
                        )
                    }
                    cardClass="border-amber-500/15 bg-amber-500/[0.04] hover:border-amber-500/30"
                    activeClass="border-amber-500/40 ring-1 ring-amber-500/25"
                    valueClass="text-amber-500 dark:text-amber-400"
                />
                </div>
            ) : null}

            {/* Search / view controls */}
            <div className="mb-8 flex flex-col gap-4 lg:flex-row lg:items-center">
                {isRosterView ? (
                    <SearchBar
                        placeholder="Search employees by name or employee no…"
                        value={searchInput}
                        onChange={setSearchInput}
                        className="mb-0 flex-1"
                    />
                ) : (
                    <div className="min-w-0 flex-1 space-y-1">
                        <p className="text-sm font-medium text-foreground/90">
                            Activity history
                        </p>
                        <p className="text-sm text-muted-foreground/80">
                            All bulk generation and email runs for{' '}
                            {selectedTypeLabel}.
                        </p>
                    </div>
                )}

                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    <BulkDocumentsViewSwitcher
                        value={view}
                        onChange={setView}
                    />

                    {isRosterView ? (
                        <>
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

                        {/* Filters button */}
                        <Button
                            type="button"
                            variant="secondary"
                            className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                            onClick={() => setFiltersOpen(true)}
                        >
                            <Filter className="mr-2 h-4 w-4" />
                            Filters
                            {activeFiltersCount ? (
                                <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                    {activeFiltersCount}
                                </span>
                            ) : null}
                        </Button>
                        </>
                    ) : null}
                </div>
            </div>

            {/* Active filter chips */}
            {isRosterView && generation_filter === 'missing' ? (
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <span className="text-xs font-medium text-muted-foreground/80">
                        Active filters
                    </span>
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
                            onClick={() =>
                                navigate(
                                    document_type_key,
                                    filters,
                                    searchInput,
                                    'all',
                                )
                            }
                            aria-label="Clear filter"
                        >
                            <X className="h-3 w-3" />
                        </Button>
                    </Badge>
                </div>
            ) : null}

            {isRosterView ? <ProgressBanner latestRun={latest_run} /> : null}

            {isRosterView ? (
                <>
            {/* Selection toolbar */}
            <DocumentsBulkToolbar
                count={selectedCount}
                itemLabel="employees"
                onClear={clear}
                selectAll={
                    <Checkbox
                        checked={
                            isAllSelected
                                ? true
                                : isPartiallySelected
                                  ? 'indeterminate'
                                  : false
                        }
                        onCheckedChange={() => toggleAll()}
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
                                disabled={selectedDocumentIds.length === 0}
                            >
                                <Trash2 className="mr-2 h-3.5 w-3.5" />
                                Delete
                            </Button>
                        ) : null}
                    </>
                }
            />

            {selectedCount > 0 ? (
                <p className="mb-3 flex items-center gap-1.5 text-xs text-muted-foreground/80">
                    <RotateCcw className="h-3 w-3" />
                    Existing documents for selected employees will be replaced.
                </p>
            ) : null}

            {/* Employee table */}
            <OrganizationDataTable minWidth="min-w-[1080px]">
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead className="w-10">
                            <Checkbox
                                checked={
                                    isAllSelected
                                        ? true
                                        : isPartiallySelected
                                          ? 'indeterminate'
                                          : false
                                }
                                onCheckedChange={() => toggleAll()}
                                aria-label="Select all employees"
                            />
                        </DataTableHead>
                        <DataTableHead>Employee</DataTableHead>
                        <DataTableHead>Position</DataTableHead>
                        <DataTableHead>Department</DataTableHead>
                        <DataTableHead>Email</DataTableHead>
                        <DataTableHead>Sponsor</DataTableHead>
                        <DataTableHead>Document</DataTableHead>
                        <DataTableHead className="text-right">
                            Actions
                        </DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {employees.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={8} className="p-0">
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
                                checked={isSelected(employee.id)}
                                onToggle={() => toggle(employee.id)}
                                canDownload={can.download}
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
                    <BulkDocumentsHistoryTable activity={activity} />

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

            <BulkDocumentsFiltersSheet
                open={filtersOpen}
                onOpenChange={setFiltersOpen}
                value={filters}
                onChange={(next) => {
                    setFilters(next);
                    navigate(document_type_key, next, searchInput);
                }}
                onReset={() => {
                    const next = { ...EMPTY_BULK_DOCUMENT_FILTERS };
                    setFilters(next);
                    navigate(document_type_key, next, searchInput);
                }}
                positions={positions}
                companyVisaTypes={company_visa_types}
            />

            <BulkDocumentsEmailModal
                open={emailOpen}
                onOpenChange={setEmailOpen}
                documentTypeKey={document_type_key}
                employeeIds={selectedIds}
                emailTemplates={email_templates}
                onSendComplete={clear}
            />

            <ConfirmDeleteDialog
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                title="Delete selected documents?"
                description={`This will permanently remove ${selectedDocumentIds.length} document(s) from employee profiles.`}
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
}: {
    employee: BulkRosterEmployee;
    checked: boolean;
    onToggle: () => void;
    canDownload: boolean;
}) {
    const hasDocument = employee.document !== null;

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
                    </div>
                </div>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {employee.position ?? '—'}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {employee.department ?? '—'}
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
                {employee.sponsor ?? '—'}
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
