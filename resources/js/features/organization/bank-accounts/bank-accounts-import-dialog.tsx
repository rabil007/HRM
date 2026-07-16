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
    importMethod as importBankAccounts,
    importPreview,
    importTemplate,
} from '@/actions/App/Http/Controllers/Organization/BankAccountsImportController';
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
    bank_name: string | null;
    iban: string | null;
    account_name: string | null;
    is_primary: boolean | null;
    action: 'create' | 'update' | 'skip';
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
    switch (action) {
        case 'create':
            return 'Create';
        case 'update':
            return 'Update';
        default:
            return 'Skip';
    }
}

export function BankAccountsImportDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}): ReactElement {
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
                row.bank_name,
                row.iban,
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

    const previewFile = useCallback(async (selected: File) => {
        setIsPreviewing(true);
        setMessage(null);

        const formData = new FormData();
        formData.append('file', selected);

        try {
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(importPreview.url(), {
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
    }, []);

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

        router.post(importBankAccounts.url(), formData, {
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
            <DialogContent className="max-h-[90vh] overflow-hidden sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Import bank accounts</DialogTitle>
                    <DialogDescription>
                        Download the template with your active employees
                        pre-filled, edit bank account fields, then upload the
                        file. Rows with no bank account data are skipped. Rows
                        are matched by employee number and IBAN or Bank Name.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 overflow-y-auto pr-1">
                    <div className="flex flex-wrap items-center gap-3">
                        <Button asChild variant="outline" size="sm">
                            <a href={importTemplate.url()}>
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
                                : 'Drop your bank accounts file here or click to browse'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Bank Accounts worksheet · .xlsx, .xls, .csv
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

                            <SearchBar
                                value={searchQuery}
                                onChange={setSearchQuery}
                                placeholder="Search by employee no., name, bank, or IBAN…"
                                className="mb-0"
                                inputClassName="py-2 text-sm"
                            />

                            <div className="max-h-72 overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Row</TableHead>
                                            <TableHead>Emp no.</TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Bank</TableHead>
                                            <TableHead>IBAN</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Action</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredRows.length === 0 ? (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={7}
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
                                                    <TableCell>
                                                        {row.employee_no}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.name ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.bank_name ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.iban ?? '—'}
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
