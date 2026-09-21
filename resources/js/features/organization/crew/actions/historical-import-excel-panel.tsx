import {
    AlertTriangle,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Loader2,
    Upload,
    XCircle,
} from 'lucide-react';
import type { DragEvent, ReactElement } from 'react';
import { Fragment, useMemo, useRef, useState } from 'react';
import HistoricalCrewAssignmentController from '@/actions/App/Http/Controllers/Organization/HistoricalCrewAssignmentController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type {
    HistoricalImportPreviewResponse,
    HistoricalImportPreviewRow,
    HistoricalImportRowStatus,
} from '../types';

type RowFilter = 'all' | HistoricalImportRowStatus;

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

export function HistoricalImportExcelPanel(): ReactElement {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [dragActive, setDragActive] = useState(false);
    const [isValidating, setIsValidating] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [preview, setPreview] =
        useState<HistoricalImportPreviewResponse | null>(null);
    const [rowFilter, setRowFilter] = useState<RowFilter>('all');
    const [expandedRow, setExpandedRow] = useState<number | null>(null);

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
        setExpandedRow(null);
        setRowFilter('all');

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

        const formData = new FormData();
        formData.append('file', file);

        try {
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
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

                return;
            }

            setPreview(payload as HistoricalImportPreviewResponse);
            setExpandedRow(null);
        } catch {
            setMessage('Unable to validate the workbook. Please try again.');
            setPreview(null);
        } finally {
            setIsValidating(false);
        }
    };

    const resetFile = () => {
        acceptFile(null);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

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
                    <AlertTitle>Phase 2 preview only</AlertTitle>
                    <AlertDescription>{preview.phase_note}</AlertDescription>
                </Alert>

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

                <div className="flex justify-between gap-2">
                    <Button type="button" variant="outline" onClick={resetFile}>
                        Back / Replace File
                    </Button>
                </div>
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
                    Upload and validate. No records are written yet.
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

            <p className="text-xs text-muted-foreground">
                Final bulk import will be enabled in Phase 3.
            </p>
        </div>
    );
}
