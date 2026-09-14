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
    importMethod as importVessels,
    importPreview,
    importTemplate,
} from '@/actions/App/Http/Controllers/Organization/VesselController';
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
    vessel_id: number | null;
    client: string | null;
    name: string | null;
    vessel_type: string | null;
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
        creates: number;
        updates: number;
        deletes: number;
        errors: number;
    };
};

function isCsvLike(file: File): boolean {
    const name = file.name.toLowerCase();

    return (
        name.endsWith('.csv') ||
        file.type === 'text/csv' ||
        file.type === 'text/plain' ||
        file.type === 'application/vnd.ms-excel'
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

export function VesselsImportDialog({
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
                row.vessel_id !== null ? String(row.vessel_id) : '',
                row.client,
                row.name,
                row.vessel_type,
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

        if (!isCsvLike(selected)) {
            setMessage('Please choose a .csv file.');

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

        router.post(importVessels.url(), formData, {
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
                    <DialogTitle>Import vessels</DialogTitle>
                    <DialogDescription>
                        Export the current fleet, edit the CSV, then upload it
                        here. Rows with vessel_id update existing vessels. Rows
                        without vessel_id create new vessels. Vessels not
                        included in the file are not changed or deleted.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 overflow-y-auto pr-1">
                    <Alert className="border-border/80 bg-muted/40">
                        <Info className="text-primary" aria-hidden />
                        <AlertDescription>
                            <ul className="list-inside list-disc space-y-1 text-muted-foreground">
                                <li>
                                    <span className="font-medium text-foreground">
                                        vessel_id
                                    </span>{' '}
                                    — existing vessel ID; keep when editing an
                                    exported row; leave blank only to create
                                </li>
                                <li>
                                    <span className="font-medium text-foreground">
                                        client
                                    </span>{' '}
                                    — active client name (required for new
                                    vessels)
                                </li>
                                <li>
                                    <span className="font-medium text-foreground">
                                        name
                                    </span>{' '}
                                    — required
                                </li>
                                <li>
                                    <span className="font-medium text-foreground">
                                        vessel_type
                                    </span>{' '}
                                    — existing vessel type name; required
                                </li>
                                <li>
                                    <span className="font-medium text-foreground">
                                        imo_no
                                    </span>
                                    ,{' '}
                                    <span className="font-medium text-foreground">
                                        official_no
                                    </span>
                                    ,{' '}
                                    <span className="font-medium text-foreground">
                                        call_sign
                                    </span>
                                    ,{' '}
                                    <span className="font-medium text-foreground">
                                        grt
                                    </span>
                                    ,{' '}
                                    <span className="font-medium text-foreground">
                                        bhp
                                    </span>
                                    ,{' '}
                                    <span className="font-medium text-foreground">
                                        is_active
                                    </span>{' '}
                                    — optional (yes/no)
                                </li>
                            </ul>
                        </AlertDescription>
                    </Alert>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button asChild variant="outline" size="sm">
                            <a href={importTemplate.url()}>
                                <Download className="mr-2 h-4 w-4" />
                                Download CSV template
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
                            accept=".csv,text/csv,text/plain,application/vnd.ms-excel"
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
                                : 'Drop your vessels CSV here or click to browse'}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            .csv only
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
                                    {preview.summary.updates} updates
                                </Badge>
                                <Badge variant="outline">
                                    {preview.summary.creates} creates
                                </Badge>
                                {preview.summary.errors > 0 ? (
                                    <Badge variant="destructive">
                                        {preview.summary.errors} errors
                                    </Badge>
                                ) : null}
                                <Badge variant="secondary">
                                    {preview.summary.deletes} deletes
                                </Badge>
                            </div>

                            <p className="text-xs text-muted-foreground">
                                No vessels will be deleted by this import.
                            </p>

                            <SearchBar
                                value={searchQuery}
                                onChange={setSearchQuery}
                                placeholder="Search by row, vessel ID, name, client, or error…"
                                className="mb-0"
                                inputClassName="py-2 text-sm"
                            />

                            <div className="max-h-72 overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Row</TableHead>
                                            <TableHead>ID</TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Client</TableHead>
                                            <TableHead>Type</TableHead>
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
                                                        {row.vessel_id ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.name ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.client ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.vessel_type ?? '—'}
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
