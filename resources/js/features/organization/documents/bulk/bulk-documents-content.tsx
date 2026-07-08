import { Link, router, usePoll } from '@inertiajs/react';
import {
    ChevronDown,
    Download,
    FileStack,
    Loader2,
    Mail,
    Trash2,
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
} from '@/components/data-table';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { SearchBar } from '@/components/search-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Spinner } from '@/components/ui/spinner';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { DepartmentEmployeeTree } from '@/features/organization/employees/components/department-employee-tree';
import { BulkDocumentsFiltersSheet, EMPTY_BULK_DOCUMENT_FILTERS } from '@/features/organization/documents/bulk/bulk-documents-filters-sheet';
import type { BulkDocumentFilters } from '@/features/organization/documents/bulk/bulk-documents-filters-sheet';
import { BulkDocumentsEmailModal } from '@/features/organization/documents/bulk/bulk-email-modal';
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
): Record<string, string> {
    const query: Record<string, string> = {
        document_type_key: documentTypeKey,
    };

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

    return query;
}

function SummaryCard({
    label,
    value,
    active,
    onClick,
    className,
}: {
    label: string;
    value: number;
    active?: boolean;
    onClick?: () => void;
    className?: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn('text-left', onClick ? 'cursor-pointer' : 'cursor-default')}
        >
            <Card
                className={cn(
                    'transition-colors',
                    onClick && 'hover:border-primary/30',
                    active && 'border-primary/30 ring-1 ring-primary/10',
                    className,
                )}
            >
                <CardContent className="p-4">
                    <p className="text-sm text-muted-foreground">{label}</p>
                    <p className="mt-1 text-2xl font-semibold tabular-nums">
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
    if (!latestRun) {
        return null;
    }

    const isRunning =
        latestRun.status === 'running' || latestRun.status === 'queued';
    const isFailed = latestRun.status === 'failed';
    const isCompleted = latestRun.status === 'completed';

    if (!isRunning && !isFailed && !isCompleted) {
        return null;
    }

    const processed =
        latestRun.generated_count +
        latestRun.replaced_count +
        latestRun.skipped_count +
        latestRun.failed_count;

    let message = '';

    if (isRunning) {
        message = `Generating… ${processed} of ${latestRun.total_targeted} done`;
    } else if (isCompleted) {
        message = `Generated ${latestRun.generated_count} document(s)`;
        if (latestRun.replaced_count > 0) {
            message += ` · ${latestRun.replaced_count} updated`;
        }
        if (latestRun.skipped_count > 0) {
            message += ` · ${latestRun.skipped_count} skipped`;
        }
    } else {
        message = 'Document generation failed. Please try again.';
    }

    return (
        <Alert
            className={cn(
                'mb-4',
                isRunning && 'border-amber-500/30 bg-amber-500/5',
                isCompleted && 'border-emerald-500/30 bg-emerald-500/5',
                isFailed && 'border-destructive/30 bg-destructive/5',
            )}
        >
            <AlertDescription className="flex items-center gap-2">
                {isRunning ? <Spinner className="h-4 w-4" /> : null}
                {message}
            </AlertDescription>
        </Alert>
    );
}

export function BulkDocumentsContent({
    document_type_key,
    document_type_options,
    filters: initialFilters,
    search: initialSearch,
    counts,
    employees,
    generation_filter,
    positions,
    company_visa_types,
    department_tree,
    department_tree_selected_id,
    department_tree_selected_position_id,
    email_templates,
    latest_run,
    recent_activity,
    can,
}: BulkDocumentsPageProps) {
    const [searchInput, setSearchInput] = useState(initialSearch);
    const [filters, setFilters] = useState<BulkDocumentFilters>({
        department_id: initialFilters.department_id,
        position_id: initialFilters.position_id,
        status: initialFilters.status || 'active',
        company_visa_type_id: initialFilters.company_visa_type_id,
    });
    const [filtersOpen, setFiltersOpen] = useState(false);
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
        { only: ['latest_run', 'counts', 'employees', 'recent_activity'], preserveScroll: true },
        { autoStart: false },
    );

    useEffect(() => {
        if (!isRunActive) {
            stop();
            return;
        }

        start();

        return () => {
            stop();
        };
    }, [isRunActive, start, stop]);

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
        ) => {
            router.get(
                BULK_URL,
                buildQuery(nextType, nextFilters, nextSearch, nextGenerationFilter),
                { preserveState: true, preserveScroll: true },
            );
        },
        [document_type_key, filters, generation_filter, searchInput],
    );

    useEffect(() => {
        const timeout = window.setTimeout(() => {
            if (searchInput !== initialSearch) {
                navigate(document_type_key, filters, searchInput);
            }
        }, 400);

        return () => window.clearTimeout(timeout);
    }, [document_type_key, filters, initialSearch, navigate, searchInput]);

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
            />

            <div className="mb-6 flex flex-wrap items-center gap-3">
                <AppSelect
                    value={document_type_key}
                    onValueChange={(value) => navigate(value)}
                    className="w-full sm:w-64"
                >
                    {document_type_options.map((option) => (
                        <AppSelectItem key={option.value} value={option.value}>
                            {option.label}
                        </AppSelectItem>
                    ))}
                </AppSelect>

                <Button
                    type="button"
                    variant="outline"
                    onClick={() => setFiltersOpen(true)}
                >
                    Filters
                </Button>

                {selectedCount === 0 && can.generate ? (
                    <Button
                        type="button"
                        onClick={handleGenerate}
                        disabled={isGenerating || missingCount === 0 || isRunActive}
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

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <SummaryCard label="In this list" value={counts.targeted} />
                <SummaryCard
                    label="Already generated"
                    value={counts.generated}
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
                />
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <SearchBar
                    value={searchInput}
                    onChange={setSearchInput}
                    placeholder="Search employees…"
                    className="mb-0 max-w-md flex-1"
                />
                <DepartmentEmployeeTree
                    nodes={department_tree}
                    selectedDepartmentId={department_tree_selected_id}
                    selectedPositionId={department_tree_selected_position_id}
                    onSelectDepartment={(departmentId) => {
                        const next = {
                            ...filters,
                            department_id: departmentId
                                ? String(departmentId)
                                : '',
                            position_id: '',
                        };
                        setFilters(next);
                        navigate(document_type_key, next, searchInput);
                    }}
                    onSelectPosition={(positionId, departmentId) => {
                        const next = {
                            ...filters,
                            department_id: String(departmentId),
                            position_id: String(positionId),
                        };
                        setFilters(next);
                        navigate(document_type_key, next, searchInput);
                    }}
                />
            </div>

            <ProgressBanner latestRun={latest_run} />

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
                                <Download className="mr-2 h-4 w-4" />
                                Download
                            </Button>
                        ) : null}
                        {can.delete ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setDeleteOpen(true)}
                                disabled={selectedDocumentIds.length === 0}
                            >
                                <Trash2 className="mr-2 h-4 w-4" />
                                Delete
                            </Button>
                        ) : null}
                        {can.email ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setEmailOpen(true)}
                            >
                                <Mail className="mr-2 h-4 w-4" />
                                Send email
                            </Button>
                        ) : null}
                    </>
                }
            />

            {selectedCount > 0 ? (
                <p className="mb-3 text-xs text-muted-foreground">
                    Already generated documents for selected employees will be
                    replaced.
                </p>
            ) : null}

            <OrganizationDataTable>
                <TableHeader>
                    <DataTableHeaderRow>
                        <DataTableHead className="w-10" />
                        <DataTableHead>Employee</DataTableHead>
                        <DataTableHead>Employee no.</DataTableHead>
                        <DataTableHead>Department</DataTableHead>
                        <DataTableHead>Sponsor</DataTableHead>
                        <DataTableHead>Status</DataTableHead>
                        <DataTableHead className="w-12" />
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {employees.length === 0 ? (
                        <TableRow>
                            <TableCell
                                colSpan={7}
                                className="py-10 text-center text-muted-foreground"
                            >
                                No employees match the current filters.
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

            <Collapsible className="mt-8">
                <CollapsibleTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        className="flex w-full items-center justify-between rounded-xl border px-4 py-3"
                    >
                        <span className="font-medium">Recent activity</span>
                        <ChevronDown className="h-4 w-4" />
                    </Button>
                </CollapsibleTrigger>
                <CollapsibleContent className="mt-3 space-y-2">
                    {recent_activity.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No recent bulk document activity yet.
                        </p>
                    ) : (
                        recent_activity.map((item) => (
                            <div
                                key={`${item.kind}-${item.id}`}
                                className="rounded-lg border px-4 py-3 text-sm"
                            >
                                {item.kind === 'generation' ? (
                                    <p>
                                        <span className="font-medium">
                                            {item.document_type_label}
                                        </span>
                                        {' · '}
                                        {item.generated_count} created
                                        {item.replaced_count > 0
                                            ? ` · ${item.replaced_count} updated`
                                            : ''}
                                        {item.skipped_count > 0
                                            ? ` · ${item.skipped_count} skipped`
                                            : ''}
                                        {item.triggered_by
                                            ? ` · ${item.triggered_by}`
                                            : ''}
                                    </p>
                                ) : (
                                    <p>
                                        Email · {item.document_type_label}
                                        {' · '}
                                        {item.sent_count} sent
                                        {item.skipped_no_email_count > 0
                                            ? ` · ${item.skipped_no_email_count} skipped (no email)`
                                            : ''}
                                        {item.template_label
                                            ? ` · ${item.template_label}`
                                            : ''}
                                    </p>
                                )}
                            </div>
                        ))
                    )}
                </CollapsibleContent>
            </Collapsible>

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
        <TableRow className={dataTableBodyRowClass}>
            <TableCell className={dataTableCellClass}>
                <Checkbox
                    checked={checked}
                    onCheckedChange={onToggle}
                    aria-label={`Select ${employee.name}`}
                />
            </TableCell>
            <TableCell className={dataTableCellClass}>{employee.name}</TableCell>
            <TableCell className={dataTableCellClass}>
                {employee.employee_no ?? '—'}
            </TableCell>
            <TableCell className={dataTableCellClass}>
                {employee.department ?? '—'}
            </TableCell>
            <TableCell className={dataTableCellClass}>
                {employee.sponsor ?? '—'}
            </TableCell>
            <TableCell className={dataTableCellClass}>
                <Badge variant={hasDocument ? 'secondary' : 'outline'}>
                    {hasDocument ? 'Generated' : 'Missing'}
                </Badge>
            </TableCell>
            <TableCell className={dataTableCellClass}>
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
                        >
                            <Download className="h-4 w-4" />
                        </a>
                    </Button>
                ) : null}
            </TableCell>
        </TableRow>
    );
}
