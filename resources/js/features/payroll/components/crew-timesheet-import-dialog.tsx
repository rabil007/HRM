import { router } from '@inertiajs/react';
import {
    AlertCircle,
    Download,
    FileSpreadsheet,
    Loader2,
    Upload,
} from 'lucide-react';
import type { DragEvent, ReactElement } from 'react';
import { useCallback, useMemo, useRef, useState } from 'react';
import {
    importPreview,
    importTemplate,
    importTimesheets,
} from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import {
    ImportPreviewFilterBadges,
    ImportPreviewFilterStatus,
} from '@/components/import-preview-filter-badges';
import { SearchBar } from '@/components/search-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    importPreviewEmptyRowsMessage,
    rowMatchesImportPreviewFilter,
    validInvalidPreviewBadges,
} from '@/lib/import-preview-row-filter';
import type { ImportPreviewRowFilter } from '@/lib/import-preview-row-filter';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

type ImportRowError = {
    row: number;
    field: string;
    message: string;
};

type ImportPreviewRow = {
    row: number;
    employee_no: string;
    name: string | null;
    department: string | null;
    position: string | null;
    sign_on_standby_days: number | null;
    onsite_days: number | null;
    sign_off_standby_days: number | null;
    total_standby_days: number | null;
    unpaid_leave_days: number | null;
    overtime_hours: number | string | null;
    remarks: string | null;
    salary_input_summary?: Array<{ name: string; amount: number }>;
    errors: ImportRowError[];
    warnings: ImportRowError[];
};

type ImportPreviewResponse = {
    rows: ImportPreviewRow[];
    errors: ImportRowError[];
    warnings: ImportRowError[];
    summary: {
        total: number;
        valid: number;
        invalid: number;
        warnings: number;
    };
};

type CrewTimesheetImportDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    periodId: number;
};

function isSpreadsheetLike(file: File): boolean {
    const name = file.name.toLowerCase();

    return (
        name.endsWith('.xlsx') ||
        name.endsWith('.xls') ||
        name.endsWith('.csv') ||
        file.type.includes('spreadsheet') ||
        file.type.includes('excel') ||
        file.type === 'text/csv'
    );
}

export function CrewTimesheetImportDialog({
    open,
    onOpenChange,
    periodId,
}: CrewTimesheetImportDialogProps): ReactElement {
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<ImportPreviewResponse | null>(null);
    const [isPreviewing, setIsPreviewing] = useState(false);
    const [isImporting, setIsImporting] = useState(false);
    const [dragActive, setDragActive] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [rowFilter, setRowFilter] = useState<ImportPreviewRowFilter>('all');
    const fileInputRef = useRef<HTMLInputElement>(null);

    const filteredRows = useMemo(() => {
        if (!preview) {
            return [];
        }

        const query = searchQuery.trim().toLowerCase();

        return preview.rows.filter((row) => {
            if (!rowMatchesImportPreviewFilter(row, rowFilter)) {
                return false;
            }

            if (query === '') {
                return true;
            }

            const searchable = [
                String(row.row),
                row.employee_no,
                row.name,
                row.department,
                row.position,
                row.sign_on_standby_days?.toString(),
                row.onsite_days?.toString(),
                row.sign_off_standby_days?.toString(),
                row.unpaid_leave_days?.toString(),
                row.overtime_hours?.toString(),
                row.remarks,
                row.errors[0]?.message,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return searchable.includes(query);
        });
    }, [preview, rowFilter, searchQuery]);

    const resetState = () => {
        setFile(null);
        setPreview(null);
        setMessage(null);
        setSearchQuery('');
        setRowFilter('all');
        setDragActive(false);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleOpenChange = (nextOpen: boolean) => {
        if (!nextOpen) {
            resetState();
        }

        onOpenChange(nextOpen);
    };

    const previewFile = useCallback(
        async (selected: File) => {
            setIsPreviewing(true);
            setMessage(null);

            const formData = new FormData();
            formData.append('file', selected);

            try {
                const csrf = document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content;
                const response = await fetch(importPreview.url(periodId), {
                    method: 'POST',
                    body: formData,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                    },
                    credentials: 'same-origin',
                });

                const data = (await response.json().catch(() => null)) as
                    | (ImportPreviewResponse & {
                          message?: string;
                          errors?: Record<string, string[]>;
                      })
                    | null;

                if (!response.ok) {
                    const fileError =
                        data?.errors?.file?.[0] ??
                        data?.message ??
                        'Could not preview the file.';
                    setMessage(fileError);
                    setPreview(null);

                    return;
                }

                setFile(selected);
                setPreview(data);
            } catch (error) {
                setMessage(
                    error instanceof Error
                        ? error.message
                        : 'Could not preview the file.',
                );
                setPreview(null);
            } finally {
                setIsPreviewing(false);
            }
        },
        [periodId],
    );

    const pickFile = (selected: File | undefined | null) => {
        if (!selected) {
            return;
        }

        if (!isSpreadsheetLike(selected)) {
            setMessage('Please choose an Excel or CSV file.');

            return;
        }

        void previewFile(selected);
    };

    const handleImport = () => {
        if (!file || !preview || preview.summary.valid === 0) {
            toast.error(
                'Upload a valid file with at least one importable row.',
            );

            return;
        }

        setIsImporting(true);

        const formData = new FormData();
        formData.append('file', file);

        router.post(importTimesheets.url(periodId), formData, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                handleOpenChange(false);
            },
            onError: (errors) => {
                setMessage(errors.file ?? 'Import failed.');
            },
            onFinish: () => {
                setIsImporting(false);
            },
        });
    };

    const onDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setDragActive(false);
        pickFile(event.dataTransfer.files?.[0]);
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col overflow-hidden sm:max-w-4xl">
                <DialogHeader className="shrink-0">
                    <DialogTitle>Import crew timesheets</DialogTitle>
                    <DialogDescription>
                        Download the template with your crew roster pre-filled.
                        Use Excel filters on Division or Department, then fill
                        the yellow date columns as DD-MM-YYYY text (e.g.
                        01-07-2026) and the orange Overtime Hours column when
                        applicable. Green columns are additions (Bonus,
                        Commission); red columns are deductions (Loan, Late,
                        etc.). Do not use the Excel date picker. Days and pay
                        are calculated when you generate payroll.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto pr-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <a href={importTemplate.url(periodId)}>
                                <Download className="mr-2 h-4 w-4" />
                                Download template
                            </a>
                        </Button>
                    </div>

                    <div
                        className={cn(
                            'flex cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed text-center transition-colors',
                            file ? 'min-h-16 p-3' : 'min-h-32 p-6',
                            dragActive
                                ? 'border-primary bg-primary/5'
                                : 'border-border/70 bg-muted/20',
                        )}
                        onDragOver={(event) => {
                            event.preventDefault();
                            setDragActive(true);
                        }}
                        onDragLeave={() => setDragActive(false)}
                        onDrop={onDrop}
                        onClick={() => fileInputRef.current?.click()}
                    >
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            className="hidden"
                            onChange={(event) =>
                                pickFile(event.target.files?.[0])
                            }
                        />
                        {isPreviewing ? (
                            <Loader2 className="mb-2 h-8 w-8 animate-spin text-muted-foreground" />
                        ) : (
                            <FileSpreadsheet className="mb-2 h-8 w-8 text-muted-foreground" />
                        )}
                        <p className="text-sm font-medium">
                            {file
                                ? file.name
                                : 'Drop your timesheet file here or click to browse'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Crew Timesheets worksheet · .xlsx, .xls, .csv
                        </p>
                    </div>

                    {message ? (
                        <Alert variant="destructive">
                            <AlertCircle className="h-4 w-4" />
                            <AlertDescription>{message}</AlertDescription>
                        </Alert>
                    ) : null}

                    {preview ? (
                        <div className="space-y-3">
                            <ImportPreviewFilterBadges
                                value={rowFilter}
                                onChange={setRowFilter}
                                badges={validInvalidPreviewBadges(
                                    preview.summary,
                                )}
                            />

                            <SearchBar
                                value={searchQuery}
                                onChange={setSearchQuery}
                                placeholder="Search by employee no., name, department, or status…"
                                className="mb-0"
                                inputClassName="py-2 text-sm"
                            />

                            <ImportPreviewFilterStatus
                                visibleCount={filteredRows.length}
                                totalCount={preview.rows.length}
                                filter={rowFilter}
                                searchQuery={searchQuery}
                            />

                            <div className="max-h-72 overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Row</TableHead>
                                            <TableHead>Emp no.</TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Sign-on</TableHead>
                                            <TableHead>Onsite</TableHead>
                                            <TableHead>Sign-off</TableHead>
                                            <TableHead>Unpaid leave</TableHead>
                                            <TableHead>Overtime</TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredRows.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={9}
                                                    className="py-8 text-center text-sm text-muted-foreground"
                                                >
                                                    {importPreviewEmptyRowsMessage(
                                                        rowFilter,
                                                        searchQuery,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            filteredRows.map((row) => (
                                                <TableRow key={row.row}>
                                                    <TableCell>
                                                        {row.row}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.employee_no}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.name ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.sign_on_standby_days ??
                                                            '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.onsite_days ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.sign_off_standby_days ??
                                                            '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.unpaid_leave_days ??
                                                            '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.overtime_hours ??
                                                            '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.errors.length >
                                                        0 ? (
                                                            <span className="text-xs text-destructive">
                                                                {
                                                                    row
                                                                        .errors[0]
                                                                        ?.message
                                                                }
                                                            </span>
                                                        ) : (
                                                            <span className="text-xs text-emerald-600">
                                                                Ready
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                            ))
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    ) : null}
                </div>

                <DialogFooter className="relative z-10 shrink-0 bg-card">
                    <Button
                        variant="outline"
                        onClick={() => handleOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={handleImport}
                        disabled={
                            !preview ||
                            preview.summary.valid === 0 ||
                            isImporting ||
                            isPreviewing
                        }
                    >
                        {isImporting ? (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        ) : (
                            <Upload className="mr-2 h-4 w-4" />
                        )}
                        Import{' '}
                        {preview ? `${preview.summary.valid} row(s)` : ''}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
