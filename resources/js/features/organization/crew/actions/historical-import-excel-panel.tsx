import {
    AlertTriangle,
    CheckCircle2,
    Download,
    Eye,
    FileSpreadsheet,
    Loader2,
    Upload,
    XCircle,
} from 'lucide-react';
import type { DragEvent, ReactElement } from 'react';
import {
    Fragment,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import HistoricalCrewAssignmentController from '@/actions/App/Http/Controllers/Organization/HistoricalCrewAssignmentController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDisplayDate, formatDisplayDateTime } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type {
    HistoricalImportBatchDetail,
    HistoricalImportBatchRow,
    HistoricalImportBatchSummary,
    HistoricalImportPreviewResponse,
    HistoricalImportPreviewRow,
    HistoricalImportRowStatus,
} from '../types';

type RowFilter = 'all' | HistoricalImportRowStatus;

function getCsrfToken(): string | undefined {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content;
}

function isSpreadsheetLike(file: File): boolean {
    const name = file.name.toLowerCase();

    return (
        name.endsWith('.xlsx') ||
        name.endsWith('.xls') ||
        file.type.includes('spreadsheet') ||
        file.type.includes('excel')
    );
}

function statusBadge(status: HistoricalImportRowStatus): ReactElement {
    if (status === 'ready') {
        return (
            <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">
                Ready
            </Badge>
        );
    }

    if (status === 'warning') {
        return (
            <Badge className="bg-amber-500 text-white hover:bg-amber-500">
                Warning
            </Badge>
        );
    }

    return <Badge variant="destructive">Blocked</Badge>;
}

function batchStatusBadge(status: string, label: string): ReactElement {
    if (status === 'completed') {
        return (
            <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">
                {label}
            </Badge>
        );
    }

    if (status === 'completed_with_errors') {
        return (
            <Badge className="bg-amber-500 text-white hover:bg-amber-500">
                {label}
            </Badge>
        );
    }

    if (status === 'importing') {
        return <Badge variant="secondary">{label}</Badge>;
    }

    return <Badge variant="destructive">{label}</Badge>;
}

function batchRowStatusBadge(status: string, label: string): ReactElement {
    if (status === 'imported') {
        return (
            <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">
                {label}
            </Badge>
        );
    }

    if (status === 'imported_with_warnings') {
        return (
            <Badge className="bg-amber-500 text-white hover:bg-amber-500">
                {label}
            </Badge>
        );
    }

    if (status === 'skipped') {
        return <Badge variant="secondary">{label}</Badge>;
    }

    if (status === 'failed') {
        return <Badge variant="destructive">{label}</Badge>;
    }

    return <Badge variant="outline">{label}</Badge>;
}

function RowDetail({ row }: { row: HistoricalImportPreviewRow }): ReactElement {
    const title =
        row.status === 'ready'
            ? `Row ${row.row} — Ready`
            : row.status === 'warning'
              ? `Row ${row.row} — Warning`
              : `Row ${row.row} — Cannot Import`;

    return (
        <div className="space-y-3 border-t border-border/60 bg-muted/20 p-4 text-sm">
            <div className="flex items-center justify-between gap-2">
                <h4 className="font-semibold text-foreground">{title}</h4>
                {statusBadge(row.status)}
            </div>

            <div>
                <span className="text-xs text-muted-foreground">Employee</span>
                <p className="font-medium">{row.employee.label}</p>
            </div>

            {row.timeline.length > 0 && (
                <div className="space-y-1">
                    <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        Timeline
                    </span>
                    <ul className="space-y-1 text-xs text-muted-foreground">
                        {row.timeline.map((item, idx) => (
                            <li key={idx}>
                                [{item.phase_code.toUpperCase()}]{' '}
                                {item.phase_label}:{' '}
                                {formatDisplayDate(item.start)}
                                {item.end
                                    ? ` → ${formatDisplayDate(item.end)}`
                                    : ''}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {row.checks.length > 0 && (
                <ul className="space-y-1 text-xs">
                    {row.checks.map((check, idx) => (
                        <li key={idx} className="flex items-start gap-2">
                            {check.passed ? (
                                <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-500" />
                            ) : (
                                <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-destructive" />
                            )}
                            <span className="text-muted-foreground">
                                {check.message}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {row.sea_service && (
                <div className="rounded-lg border border-border/60 bg-card p-3 text-xs">
                    <p className="font-semibold text-foreground">Sea Service</p>
                    <p className="text-muted-foreground">
                        {row.sea_service.message}
                    </p>
                </div>
            )}

            {row.conflicting_assignment && (
                <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-xs">
                    <p className="font-semibold text-destructive">
                        Existing assignment conflict
                    </p>
                    <p className="text-muted-foreground">
                        {row.conflicting_assignment.assignment_no}
                        {row.conflicting_assignment.started_at
                            ? ` · ${row.conflicting_assignment.started_at}`
                            : ''}
                        {row.conflicting_assignment.closed_at
                            ? ` → ${row.conflicting_assignment.closed_at}`
                            : ''}
                    </p>
                </div>
            )}

            {row.warnings.length > 0 && (
                <ul className="list-inside list-disc space-y-0.5 text-xs text-amber-700 dark:text-amber-400">
                    {row.warnings.map((warning, idx) => (
                        <li key={idx}>{warning}</li>
                    ))}
                </ul>
            )}

            {row.errors.length > 0 && (
                <ul className="list-inside list-disc space-y-0.5 text-xs text-destructive">
                    {row.errors.map((error, idx) => (
                        <li key={idx}>{error}</li>
                    ))}
                </ul>
            )}

            {row.workbook_messages.length > 0 && (
                <ul className="list-inside list-disc space-y-0.5 text-xs text-destructive">
                    {row.workbook_messages.map((message, idx) => (
                        <li key={idx}>{message}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function BatchRowDetail({
    row,
}: {
    row: HistoricalImportBatchRow;
}): ReactElement {
    return (
        <div className="space-y-3 border-t border-border/60 bg-muted/20 p-4 text-sm">
            <div className="flex items-center justify-between gap-2">
                <h4 className="font-semibold text-foreground">
                    Row {row.row} — {row.status_label}
                </h4>
                {batchRowStatusBadge(row.status, row.status_label)}
            </div>

            {row.assignment_no ? (
                <p className="text-xs text-muted-foreground">
                    Assignment:{' '}
                    <span className="font-medium text-foreground">
                        {row.assignment_no}
                    </span>
                </p>
            ) : null}

            {row.warnings.length > 0 && (
                <ul className="list-inside list-disc space-y-0.5 text-xs text-amber-700 dark:text-amber-400">
                    {row.warnings.map((warning, idx) => (
                        <li key={idx}>{warning}</li>
                    ))}
                </ul>
            )}

            {row.errors.length > 0 && (
                <ul className="list-inside list-disc space-y-0.5 text-xs text-destructive">
                    {row.errors.map((error, idx) => (
                        <li key={idx}>{error}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function RecentImportsSection({
    batches,
    isLoading,
    onView,
    viewingBatchId,
}: {
    batches: HistoricalImportBatchSummary[];
    isLoading: boolean;
    onView: (batchId: number) => void;
    viewingBatchId: number | null;
}): ReactElement | null {
    if (!isLoading && batches.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <h4 className="text-sm font-medium text-foreground">
                Recent Imports
            </h4>

            {isLoading ? (
                <p className="text-xs text-muted-foreground">
                    Loading recent imports…
                </p>
            ) : (
                <div className="overflow-hidden rounded-xl border border-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Batch</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>File</TableHead>
                                <TableHead>Imported / Blocked</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-20" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {batches.map((batch) => (
                                <TableRow key={batch.id}>
                                    <TableCell className="font-medium">
                                        {batch.batch_no}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {formatDisplayDateTime(
                                            batch.completed_at ??
                                                batch.created_at,
                                        )}
                                    </TableCell>
                                    <TableCell
                                        className="max-w-[140px] truncate text-xs"
                                        title={batch.original_filename}
                                    >
                                        {batch.original_filename}
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        {batch.imported_rows} /{' '}
                                        {batch.blocked_rows}
                                    </TableCell>
                                    <TableCell>
                                        {batchStatusBadge(
                                            batch.status,
                                            batch.status_label,
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="h-8 gap-1 px-2"
                                            disabled={
                                                viewingBatchId === batch.id
                                            }
                                            onClick={() => onView(batch.id)}
                                        >
                                            {viewingBatchId === batch.id ? (
                                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                            ) : (
                                                <Eye className="h-3.5 w-3.5" />
                                            )}
                                            View
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </div>
    );
}

export function HistoricalImportExcelPanel(): ReactElement {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [dragActive, setDragActive] = useState(false);
    const [isValidating, setIsValidating] = useState(false);
    const [isImporting, setIsImporting] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [preview, setPreview] =
        useState<HistoricalImportPreviewResponse | null>(null);
    const [importResult, setImportResult] =
        useState<HistoricalImportBatchDetail | null>(null);
    const [recentImports, setRecentImports] = useState<
        HistoricalImportBatchSummary[]
    >([]);
    const [isLoadingRecentImports, setIsLoadingRecentImports] = useState(true);
    const [viewingBatchId, setViewingBatchId] = useState<number | null>(null);
    const [reviewConfirmed, setReviewConfirmed] = useState(false);
    const [idempotencyKey, setIdempotencyKey] = useState<string | null>(null);
    const [rowFilter, setRowFilter] = useState<RowFilter>('all');
    const [expandedRow, setExpandedRow] = useState<number | null>(null);
    const [expandedBatchRow, setExpandedBatchRow] = useState<number | null>(
        null,
    );

    const loadRecentImports = useCallback(async () => {
        setIsLoadingRecentImports(true);

        try {
            const csrf = getCsrfToken();
            const response = await fetch(
                HistoricalCrewAssignmentController.importBatches.url(),
                {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                    },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                return;
            }

            const payload = (await response.json()) as {
                batches?: HistoricalImportBatchSummary[];
            };

            setRecentImports(payload.batches ?? []);
        } catch {
            // Non-blocking: recent imports are supplementary context.
        } finally {
            setIsLoadingRecentImports(false);
        }
    }, []);

    useEffect(() => {
        void loadRecentImports();
    }, [loadRecentImports]);

    const importableCount = useMemo(() => {
        if (!preview) {
            return 0;
        }

        return (
            preview.summary.importable ??
            preview.summary.ready + preview.summary.warning
        );
    }, [preview]);

    const filteredRows = useMemo(() => {
        if (!preview) {
            return [];
        }

        if (rowFilter === 'all') {
            return preview.rows;
        }

        return preview.rows.filter((row) => row.status === rowFilter);
    }, [preview, rowFilter]);

    const acceptFile = (next: File | null) => {
        setMessage(null);
        setPreview(null);
        setImportResult(null);
        setExpandedRow(null);
        setExpandedBatchRow(null);
        setRowFilter('all');
        setReviewConfirmed(false);
        setIdempotencyKey(null);

        if (!next) {
            setFile(null);

            return;
        }

        if (!isSpreadsheetLike(next)) {
            setMessage('Please choose an Excel workbook (.xlsx or .xls).');
            setFile(null);

            return;
        }

        setFile(next);
    };

    const onDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setDragActive(false);
        const dropped = event.dataTransfer.files?.[0] ?? null;
        acceptFile(dropped);
    };

    const handleValidate = async () => {
        if (!file) {
            setMessage('Please choose an Excel file to validate.');

            return;
        }

        setIsValidating(true);
        setMessage(null);
        setImportResult(null);

        const formData = new FormData();
        formData.append('file', file);

        try {
            const csrf = getCsrfToken();
            const response = await fetch(
                HistoricalCrewAssignmentController.importValidate.url(),
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                    },
                    credentials: 'same-origin',
                },
            );

            const payload = (await response.json().catch(() => null)) as
                | HistoricalImportPreviewResponse
                | {
                      message?: string;
                      errors?: Record<string, string[]>;
                  }
                | null;

            if (!response.ok) {
                const errors =
                    payload && 'errors' in payload ? payload.errors : undefined;
                const fileError = errors?.file?.[0];
                setMessage(
                    fileError ??
                        (payload && 'message' in payload
                            ? payload.message
                            : null) ??
                        'Validation failed. Please check the uploaded file.',
                );
                setPreview(null);
                setIdempotencyKey(null);

                return;
            }

            const nextPreview = payload as HistoricalImportPreviewResponse;
            setPreview(nextPreview);
            setIdempotencyKey(crypto.randomUUID());
            setReviewConfirmed(false);
            setExpandedRow(null);

            if (nextPreview.recent_imports) {
                setRecentImports(nextPreview.recent_imports);
            }
        } catch {
            setMessage('Unable to validate the workbook. Please try again.');
            setPreview(null);
            setIdempotencyKey(null);
        } finally {
            setIsValidating(false);
        }
    };

    const handleImport = async () => {
        if (!file || !preview || !idempotencyKey || !reviewConfirmed) {
            return;
        }

        setIsImporting(true);
        setMessage(null);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('confirmed', '1');
        formData.append('idempotency_key', idempotencyKey);

        try {
            const csrf = getCsrfToken();
            const response = await fetch(
                HistoricalCrewAssignmentController.importExecute.url(),
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                    },
                    credentials: 'same-origin',
                },
            );

            const payload = (await response.json().catch(() => null)) as
                | HistoricalImportBatchDetail
                | {
                      message?: string;
                      errors?: Record<string, string[]>;
                  }
                | null;

            if (!response.ok) {
                const errors =
                    payload && 'errors' in payload ? payload.errors : undefined;
                const fileError = errors?.file?.[0];
                const keyError = errors?.idempotency_key?.[0];
                setMessage(
                    fileError ??
                        keyError ??
                        (payload && 'message' in payload
                            ? payload.message
                            : null) ??
                        'Import failed. Please try again.',
                );

                return;
            }

            const result = payload as HistoricalImportBatchDetail;
            setImportResult(result);
            setPreview(null);
            setReviewConfirmed(false);
            setIdempotencyKey(null);
            void loadRecentImports();
        } catch {
            setMessage('Unable to import the workbook. Please try again.');
        } finally {
            setIsImporting(false);
        }
    };

    const handleViewBatch = async (batchId: number) => {
        setViewingBatchId(batchId);
        setMessage(null);

        try {
            const csrf = getCsrfToken();
            const response = await fetch(
                HistoricalCrewAssignmentController.importBatchShow.url({
                    batch: batchId,
                }),
                {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                    },
                    credentials: 'same-origin',
                },
            );

            if (!response.ok) {
                setMessage('Unable to load import batch details.');

                return;
            }

            const detail =
                (await response.json()) as HistoricalImportBatchDetail;
            setImportResult(detail);
            setPreview(null);
            setExpandedBatchRow(null);
        } catch {
            setMessage('Unable to load import batch details.');
        } finally {
            setViewingBatchId(null);
        }
    };

    const resetFile = () => {
        acceptFile(null);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const resetToUpload = () => {
        resetFile();
        setImportResult(null);
        setExpandedBatchRow(null);
    };

    if (importResult) {
        return (
            <div className="space-y-4 pt-2">
                <div className="space-y-1">
                    <h3 className="text-base font-semibold text-foreground">
                        Import Result — {importResult.batch_no}
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {importResult.original_filename}
                    </p>
                </div>

                <div className="grid grid-cols-3 gap-2 text-center text-sm">
                    <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-3">
                        <div className="text-lg font-semibold text-emerald-600">
                            {importResult.imported_rows}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Imported
                        </div>
                    </div>
                    <div className="rounded-lg border border-border bg-card p-3">
                        <div className="text-lg font-semibold text-muted-foreground">
                            {importResult.skipped_rows}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Blocked / Skipped
                        </div>
                    </div>
                    <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-3">
                        <div className="text-lg font-semibold text-destructive">
                            {importResult.failed_rows}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Failed
                        </div>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {batchStatusBadge(
                        importResult.status,
                        importResult.status_label,
                    )}
                    {importResult.imported_with_warnings > 0 ? (
                        <span className="text-xs text-amber-700 dark:text-amber-400">
                            {importResult.imported_with_warnings} imported with
                            warnings
                        </span>
                    ) : null}
                </div>

                {importResult.resumed ? (
                    <Alert>
                        <CheckCircle2 className="h-4 w-4" />
                        <AlertTitle>Import resumed</AlertTitle>
                        <AlertDescription>
                            This confirmation continued an interrupted import.
                            Already imported rows were not duplicated.
                        </AlertDescription>
                    </Alert>
                ) : null}

                <Button variant="outline" size="sm" className="gap-2" asChild>
                    <a
                        href={HistoricalCrewAssignmentController.importBatchResultDownload.url(
                            { batch: importResult.id },
                        )}
                    >
                        <Download className="h-4 w-4" />
                        Download Result Workbook
                    </a>
                </Button>

                <div className="overflow-hidden rounded-xl border border-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-14">Row</TableHead>
                                <TableHead>Employee</TableHead>
                                <TableHead>Vessel</TableHead>
                                <TableHead>Rank</TableHead>
                                <TableHead>Assignment</TableHead>
                                <TableHead className="w-36">Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {importResult.rows.map((row) => (
                                <Fragment key={row.row}>
                                    <TableRow
                                        className="cursor-pointer"
                                        onClick={() =>
                                            setExpandedBatchRow((current) =>
                                                current === row.row
                                                    ? null
                                                    : row.row,
                                            )
                                        }
                                    >
                                        <TableCell className="font-medium">
                                            {row.row}
                                        </TableCell>
                                        <TableCell>
                                            {row.employee_name ??
                                                row.employee_no ??
                                                '—'}
                                        </TableCell>
                                        <TableCell>
                                            {row.vessel ?? '—'}
                                        </TableCell>
                                        <TableCell>{row.rank ?? '—'}</TableCell>
                                        <TableCell>
                                            {row.assignment_no ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {batchRowStatusBadge(
                                                row.status,
                                                row.status_label,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                    {expandedBatchRow === row.row ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={6}
                                                className="p-0"
                                            >
                                                <BatchRowDetail row={row} />
                                            </TableCell>
                                        </TableRow>
                                    ) : null}
                                </Fragment>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex justify-between gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={resetToUpload}
                    >
                        Back / New Import
                    </Button>
                </div>
            </div>
        );
    }

    if (isImporting) {
        return (
            <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <Loader2 className="h-8 w-8 animate-spin text-primary" />
                <p className="text-sm font-medium text-foreground">
                    Importing historical assignments…
                </p>
            </div>
        );
    }

    if (preview) {
        return (
            <div className="space-y-4 pt-2">
                <div className="space-y-1">
                    <h3 className="text-base font-semibold text-foreground">
                        Historical Import Validation
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {preview.summary.total} rows detected
                    </p>
                </div>

                <div className="grid grid-cols-3 gap-2 text-center text-sm">
                    <button
                        type="button"
                        onClick={() =>
                            setRowFilter((current) =>
                                current === 'ready' ? 'all' : 'ready',
                            )
                        }
                        className={cn(
                            'rounded-lg border p-3 transition-colors',
                            rowFilter === 'ready'
                                ? 'border-emerald-500/40 bg-emerald-500/10'
                                : 'border-border bg-card',
                        )}
                    >
                        <div className="text-lg font-semibold text-emerald-600">
                            {preview.summary.ready}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Ready
                        </div>
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            setRowFilter((current) =>
                                current === 'warning' ? 'all' : 'warning',
                            )
                        }
                        className={cn(
                            'rounded-lg border p-3 transition-colors',
                            rowFilter === 'warning'
                                ? 'border-amber-500/40 bg-amber-500/10'
                                : 'border-border bg-card',
                        )}
                    >
                        <div className="text-lg font-semibold text-amber-600">
                            {preview.summary.warning}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Warnings
                        </div>
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            setRowFilter((current) =>
                                current === 'blocked' ? 'all' : 'blocked',
                            )
                        }
                        className={cn(
                            'rounded-lg border p-3 transition-colors',
                            rowFilter === 'blocked'
                                ? 'border-destructive/40 bg-destructive/5'
                                : 'border-border bg-card',
                        )}
                    >
                        <div className="text-lg font-semibold text-destructive">
                            {preview.summary.blocked}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Blocked
                        </div>
                    </button>
                </div>

                <Alert>
                    <AlertTriangle className="h-4 w-4" />
                    <AlertTitle>Before you import</AlertTitle>
                    <AlertDescription>{preview.phase_note}</AlertDescription>
                </Alert>

                <p className="text-sm text-muted-foreground">
                    Ready + Warning rows can be imported. Blocked rows will not
                    be imported.
                </p>

                {preview.summary.blocked > 0 ? (
                    <p className="text-sm text-destructive">
                        {preview.summary.blocked} blocked rows will not be
                        imported.
                    </p>
                ) : null}

                <div className="overflow-hidden rounded-xl border border-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-14">Row</TableHead>
                                <TableHead>Employee</TableHead>
                                <TableHead>Vessel</TableHead>
                                <TableHead>Join</TableHead>
                                <TableHead>Sign Off</TableHead>
                                <TableHead className="w-24">Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filteredRows.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-8 text-center text-sm text-muted-foreground"
                                    >
                                        No rows match this filter.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                filteredRows.map((row) => (
                                    <Fragment key={row.row}>
                                        <TableRow
                                            className="cursor-pointer"
                                            onClick={() =>
                                                setExpandedRow((current) =>
                                                    current === row.row
                                                        ? null
                                                        : row.row,
                                                )
                                            }
                                        >
                                            <TableCell className="font-medium">
                                                {row.row}
                                            </TableCell>
                                            <TableCell>
                                                {row.employee.label}
                                            </TableCell>
                                            <TableCell>
                                                {row.vessel.name ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {row.joined_vessel_at
                                                    ? formatDisplayDate(
                                                          row.joined_vessel_at,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                {row.disembarked_at
                                                    ? formatDisplayDate(
                                                          row.disembarked_at,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                {statusBadge(row.status)}
                                            </TableCell>
                                        </TableRow>
                                        {expandedRow === row.row ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={6}
                                                    className="p-0"
                                                >
                                                    <RowDetail row={row} />
                                                </TableCell>
                                            </TableRow>
                                        ) : null}
                                    </Fragment>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                <div className="flex items-center gap-3">
                    <Checkbox
                        id="historical-import-review-confirmed"
                        checked={reviewConfirmed}
                        onCheckedChange={(checked) =>
                            setReviewConfirmed(checked === true)
                        }
                    />
                    <label
                        htmlFor="historical-import-review-confirmed"
                        className="text-sm text-muted-foreground"
                    >
                        I reviewed the validation results
                    </label>
                </div>

                {message ? (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertTitle>Import failed</AlertTitle>
                        <AlertDescription>{message}</AlertDescription>
                    </Alert>
                ) : null}

                <div className="flex justify-between gap-2">
                    <Button type="button" variant="outline" onClick={resetFile}>
                        Back / Replace File
                    </Button>
                    <Button
                        type="button"
                        onClick={handleImport}
                        disabled={
                            importableCount === 0 ||
                            !reviewConfirmed ||
                            isImporting
                        }
                        className="gap-2"
                    >
                        Import {importableCount} Valid Rows
                    </Button>
                </div>

                <RecentImportsSection
                    batches={recentImports}
                    isLoading={isLoadingRecentImports}
                    onView={handleViewBatch}
                    viewingBatchId={viewingBatchId}
                />
            </div>
        );
    }

    return (
        <div className="space-y-5 pt-2">
            <div>
                <h3 className="text-base font-semibold text-foreground">
                    Import Historical Crew Data
                </h3>
                <div className="mt-1 h-px w-full bg-border" />
            </div>

            <div className="space-y-2">
                <p className="text-sm font-medium text-foreground">Step 1</p>
                <p className="text-sm text-muted-foreground">
                    Download our Excel template.
                </p>
                <Button variant="outline" size="sm" className="gap-2" asChild>
                    <a
                        href={HistoricalCrewAssignmentController.importTemplate.url()}
                    >
                        <Download className="h-4 w-4" />
                        Download Template
                    </a>
                </Button>
            </div>

            <div className="space-y-1">
                <p className="text-sm font-medium text-foreground">Step 2</p>
                <p className="text-sm text-muted-foreground">
                    Fill the file with historical assignments using Reference
                    Data values.
                </p>
            </div>

            <div className="space-y-2">
                <p className="text-sm font-medium text-foreground">Step 3</p>
                <p className="text-sm text-muted-foreground">
                    Upload and validate your workbook. After review, import
                    Ready and Warning rows.
                </p>

                <div
                    onDragEnter={(event) => {
                        event.preventDefault();
                        setDragActive(true);
                    }}
                    onDragOver={(event) => {
                        event.preventDefault();
                        setDragActive(true);
                    }}
                    onDragLeave={(event) => {
                        event.preventDefault();
                        setDragActive(false);
                    }}
                    onDrop={onDrop}
                    className={cn(
                        'flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-6 py-10 text-center transition-colors',
                        dragActive
                            ? 'border-primary bg-primary/5'
                            : 'border-border bg-muted/20',
                    )}
                >
                    <div className="flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <Upload className="h-6 w-6" />
                    </div>
                    <p className="text-sm font-medium text-foreground">
                        Drop Excel file here
                    </p>
                    <p className="text-xs text-muted-foreground">or</p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => fileInputRef.current?.click()}
                    >
                        Choose File
                    </Button>
                    <input
                        ref={fileInputRef}
                        type="file"
                        accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                        className="hidden"
                        onChange={(event) =>
                            acceptFile(event.target.files?.[0] ?? null)
                        }
                    />
                    {file ? (
                        <p className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                            <FileSpreadsheet className="h-3.5 w-3.5" />
                            {file.name}
                        </p>
                    ) : null}
                </div>
            </div>

            {message ? (
                <Alert variant="destructive">
                    <AlertTriangle className="h-4 w-4" />
                    <AlertTitle>Validation failed</AlertTitle>
                    <AlertDescription>{message}</AlertDescription>
                </Alert>
            ) : null}

            <div className="flex justify-end">
                <Button
                    type="button"
                    onClick={handleValidate}
                    disabled={!file || isValidating}
                    className="gap-2"
                >
                    {isValidating ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : null}
                    Validate File
                </Button>
            </div>

            <RecentImportsSection
                batches={recentImports}
                isLoading={isLoadingRecentImports}
                onView={handleViewBatch}
                viewingBatchId={viewingBatchId}
            />
        </div>
    );
}
