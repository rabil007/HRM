import { router } from '@inertiajs/react';
import {
    AlertCircle,
    Download,
    FileSpreadsheet,
    Info,
    Loader2,
    Upload,
} from 'lucide-react';
import type { DragEvent, ReactElement } from 'react';
import { useCallback, useMemo, useRef, useState } from 'react';
import {
    importMethod as importEmployeeSeaServices,
    importPreview as importEmployeeSeaServicesPreview,
    importTemplate as importEmployeeSeaServicesTemplate,
} from '@/actions/App/Http/Controllers/Organization/EmployeeSeaServiceController';
import {
    importMethod as importSeaServices,
    importPreview as importSeaServicesPreview,
    importTemplate as importSeaServicesTemplate,
} from '@/actions/App/Http/Controllers/Organization/SeaServicesImportController';
import { SearchBar } from '@/components/search-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
    vessel_type: string | null;
    vessel: string | null;
    rank: string | null;
    start_date: string | null;
    end_date: string | null;
    client: string | null;
    action: 'create' | 'skip';
    errors: ImportRowError[];
};

type ImportPreviewResponse = {
    rows: ImportPreviewRow[];
    errors: ImportRowError[];
    warnings: ImportRowError[];
    summary: {
        total: number;
        valid: number;
        invalid: number;
        importable: number;
        skipped: number;
        warnings: number;
    };
};

export type SeaServicesImportEmployeeContext = {
    id: number;
    employee_no: string;
    name: string;
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

function actionLabel(action: ImportPreviewRow['action']): string {
    return action === 'create' ? 'Create' : 'Skip';
}

function resolveUrls(employee: SeaServicesImportEmployeeContext | null): {
    templateUrl: string;
    previewUrl: string;
    importUrl: string;
} {
    if (employee) {
        return {
            templateUrl: importEmployeeSeaServicesTemplate.url({
                employee: employee.id,
            }),
            previewUrl: importEmployeeSeaServicesPreview.url({
                employee: employee.id,
            }),
            importUrl: importEmployeeSeaServices.url({ employee: employee.id }),
        };
    }

    return {
        templateUrl: importSeaServicesTemplate.url(),
        previewUrl: importSeaServicesPreview.url(),
        importUrl: importSeaServices.url(),
    };
}

export function SeaServicesImportDialog({
    open,
    onOpenChange,
    employee = null,
    reloadOnly,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee?: SeaServicesImportEmployeeContext | null;
    reloadOnly?: string[];
}): ReactElement {
    const urls = useMemo(() => resolveUrls(employee), [employee]);
    const isEmployeeScoped = employee !== null;

    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<ImportPreviewResponse | null>(null);
    const [isPreviewing, setIsPreviewing] = useState(false);
    const [isImporting, setIsImporting] = useState(false);
    const [dragActive, setDragActive] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const fileInputRef = useRef<HTMLInputElement>(null);

    const filteredRows = useMemo(() => {
        if (!preview) {
            return [];
        }

        const query = searchQuery.trim().toLowerCase();

        if (query === '') {
            return preview.rows;
        }

        return preview.rows.filter((row) => {
            const searchable = [
                String(row.row),
                row.employee_no,
                row.name,
                row.vessel_type,
                row.vessel,
                row.rank,
                row.client,
                row.action,
                row.errors[0]?.message,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return searchable.includes(query);
        });
    }, [preview, searchQuery]);

    const resetState = () => {
        setFile(null);
        setPreview(null);
        setMessage(null);
        setSearchQuery('');
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
                const response = await fetch(urls.previewUrl, {
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
        [urls.previewUrl],
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
        if (!file || !preview || preview.summary.importable === 0) {
            toast.error(
                'Upload a valid file with at least one importable row.',
            );

            return;
        }

        setIsImporting(true);

        const formData = new FormData();
        formData.append('file', file);

        router.post(urls.importUrl, formData, {
            forceFormData: true,
            preserveScroll: true,
            ...(reloadOnly ? { only: reloadOnly } : {}),
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
            <DialogContent className="max-h-[90vh] overflow-hidden sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Import sea services</DialogTitle>
                    <DialogDescription>
                        {isEmployeeScoped
                            ? `Download the template for ${employee.name}, fill vessel / rank / dates on each row, then upload. Empty rows are skipped. Preview validates master-data names before import.`
                            : 'Download the template with active employees pre-filled, fill vessel, rank and dates, then upload. Rows with no sea service data are skipped. Multiple rows per employee are allowed.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 overflow-y-auto pr-1">
                    <Alert className="border-border/80 bg-muted/40">
                        <Info className="text-primary" aria-hidden />
                        <AlertDescription>
                            <p className="mb-2 text-sm font-medium text-foreground">
                                Before you import
                            </p>
                            <ul className="list-disc space-y-1 pl-4 text-sm text-muted-foreground">
                                <li>
                                    Use the downloaded template headers exactly
                                    (Employee No, Vessel Type, Vessel, Rank,
                                    Start Date, End Date, Client).
                                </li>
                                <li>
                                    Vessel type, vessel, rank, and client must
                                    match active names in Settings → Master
                                    Data.
                                </li>
                                <li>
                                    Dates accept YYYY-MM-DD or DD/MM/YYYY (also
                                    D/M/YY). End date must be on or after start
                                    date.
                                </li>
                                <li>
                                    Leave vessel / rank / dates blank to skip a
                                    pre-filled employee row. Only rows with sea
                                    service data are imported.
                                </li>
                                {isEmployeeScoped ? (
                                    <li>
                                        Keep employee number{' '}
                                        <span className="font-medium text-foreground">
                                            {employee.employee_no}
                                        </span>{' '}
                                        unchanged — other employee numbers are
                                        rejected.
                                    </li>
                                ) : (
                                    <li>
                                        Do not change Employee No values from
                                        the template unless you intend to import
                                        for a different employee.
                                    </li>
                                )}
                            </ul>
                        </AlertDescription>
                    </Alert>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button asChild variant="outline" size="sm">
                            <a href={urls.templateUrl}>
                                <Download className="mr-2 h-4 w-4" />
                                Download template
                            </a>
                        </Button>
                    </div>

                    <div
                        className={cn(
                            'flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed p-6 text-center transition-colors',
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
                                : 'Drop your sea service file here or click to browse'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Sea Services worksheet · .xlsx, .xls, .csv · preview
                            runs automatically
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
                            <div className="flex flex-wrap gap-2">
                                <Badge variant="secondary">
                                    {preview.summary.total} rows
                                </Badge>
                                <Badge variant="default">
                                    {preview.summary.importable} importable
                                </Badge>
                                {preview.summary.skipped > 0 ? (
                                    <Badge variant="outline">
                                        {preview.summary.skipped} skipped
                                    </Badge>
                                ) : null}
                                {preview.summary.invalid > 0 ? (
                                    <Badge variant="destructive">
                                        {preview.summary.invalid} invalid
                                    </Badge>
                                ) : null}
                            </div>

                            {preview.summary.importable === 0 &&
                            preview.summary.invalid === 0 ? (
                                <Alert>
                                    <Info className="h-4 w-4" />
                                    <AlertDescription>
                                        All rows are skipped because vessel,
                                        rank, or dates are empty. Fill those
                                        columns for the rows you want to import,
                                        then re-upload.
                                    </AlertDescription>
                                </Alert>
                            ) : null}

                            <SearchBar
                                value={searchQuery}
                                onChange={setSearchQuery}
                                placeholder="Search by employee no., vessel, or rank…"
                                className="mb-0"
                                inputClassName="py-2 text-sm"
                            />

                            <div className="max-h-72 overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Row</TableHead>
                                            {!isEmployeeScoped ? (
                                                <>
                                                    <TableHead>
                                                        Emp no.
                                                    </TableHead>
                                                    <TableHead>Name</TableHead>
                                                </>
                                            ) : null}
                                            <TableHead>Vessel type</TableHead>
                                            <TableHead>Vessel</TableHead>
                                            <TableHead>Rank</TableHead>
                                            <TableHead>Dates</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Action</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredRows.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={
                                                        isEmployeeScoped ? 7 : 9
                                                    }
                                                    className="py-8 text-center text-sm text-muted-foreground"
                                                >
                                                    No rows match your search.
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            filteredRows.map((row) => (
                                                <TableRow key={row.row}>
                                                    <TableCell>
                                                        {row.row}
                                                    </TableCell>
                                                    {!isEmployeeScoped ? (
                                                        <>
                                                            <TableCell>
                                                                {
                                                                    row.employee_no
                                                                }
                                                            </TableCell>
                                                            <TableCell>
                                                                {row.name ??
                                                                    '—'}
                                                            </TableCell>
                                                        </>
                                                    ) : null}
                                                    <TableCell>
                                                        {row.vessel_type ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.vessel ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.rank ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                                                        {row.start_date ?? '—'}{' '}
                                                        → {row.end_date ?? '—'}
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
                                                                Valid
                                                            </span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {actionLabel(
                                                            row.action,
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

                <DialogFooter>
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
                            preview.summary.importable === 0 ||
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
                        {preview ? `${preview.summary.importable} row(s)` : ''}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
