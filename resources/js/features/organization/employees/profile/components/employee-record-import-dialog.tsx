import { router } from '@inertiajs/react';
import { AlertCircle, Download, FileSpreadsheet, Info, Loader2, Upload } from 'lucide-react';
import type { DragEvent, KeyboardEvent, ReactElement, ReactNode } from 'react';
import { useRef, useState } from 'react';
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
import { firstValidationError, hasFlashSuccess } from '@/lib/first-validation-error';
import { cn } from '@/lib/utils';

export type EmployeeRecordImportDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employeeId: number;
    inputId: string;
    title: string;
    description: string;
    templateHint: string;
    columnHelp: ReactNode;
    importUrl: string;
    templateUrl: string;
    reloadOnly: string[];
};

export function EmployeeRecordImportDialog({
    open,
    onOpenChange,
    employeeId,
    inputId,
    title,
    description,
    templateHint,
    columnHelp,
    importUrl,
    templateUrl,
    reloadOnly,
}: EmployeeRecordImportDialogProps): ReactElement {
    const [importFile, setImportFile] = useState<File | null>(null);
    const [importMessage, setImportMessage] = useState<string | null>(null);
    const [importProcessing, setImportProcessing] = useState(false);
    const [importDragActive, setImportDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const resetState = () => {
        setImportFile(null);
        setImportMessage(null);
        setImportDragActive(false);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const isCsvLike = (file: File): boolean =>
        file.type === 'text/csv' ||
        file.type === 'application/vnd.ms-excel' ||
        file.type === 'text/plain' ||
        file.name.toLowerCase().endsWith('.csv');

    const pickImportFile = (file: File | undefined | null) => {
        if (!file) {
            return;
        }

        if (!isCsvLike(file)) {
            setImportMessage('Please choose a .csv file.');

            return;
        }

        setImportFile(file);
        setImportMessage(null);
    };

    const clearImportFile = () => {
        setImportFile(null);
        setImportMessage(null);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const onImportDrag = (event: DragEvent) => {
        event.preventDefault();
        event.stopPropagation();
    };

    const onImportDrop = (event: DragEvent) => {
        event.preventDefault();
        event.stopPropagation();
        setImportDragActive(false);
        pickImportFile(event.dataTransfer.files?.[0] ?? null);
    };

    const onImportDragLeave = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        event.stopPropagation();
        const next = event.relatedTarget as Node | null;

        if (!event.currentTarget.contains(next)) {
            setImportDragActive(false);
        }
    };

    const runImport = () => {
        if (!importFile) {
            return;
        }

        setImportMessage(null);
        setImportProcessing(true);
        router.post(
            importUrl,
            { file: importFile },
            {
                preserveScroll: true,
                only: reloadOnly,
                forceFormData: true,
                onFinish: () => setImportProcessing(false),
                onSuccess: (page) => {
                    if (hasFlashSuccess(page)) {
                        onOpenChange(false);
                        resetState();
                    }
                },
                onError: (errs) =>
                    setImportMessage(
                        firstValidationError(
                            errs as Record<string, string | string[]>,
                            'file',
                            'Import failed.',
                        ),
                    ),
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(nextOpen) => {
                onOpenChange(nextOpen);

                if (!nextOpen) {
                    resetState();
                }
            }}
        >
            <DialogContent className="gap-0 overflow-hidden border-border p-0 sm:max-w-lg">
                <DialogHeader className="space-y-0 border-b border-border px-6 py-5 text-left sm:text-left">
                    <div className="flex gap-4">
                        <div
                            className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary shadow-inner ring-1 ring-primary/15"
                            aria-hidden
                        >
                            <Upload className="size-5" />
                        </div>
                        <div className="min-w-0 space-y-1.5 pt-0.5">
                            <DialogTitle className="text-xl leading-tight">{title}</DialogTitle>
                            <DialogDescription>{description}</DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="space-y-5 px-6 py-5">
                    <Alert className="border-border/80 bg-muted/40">
                        <Info className="text-primary" aria-hidden />
                        <AlertDescription>
                            <span className="sr-only">CSV columns</span>
                            {columnHelp}
                        </AlertDescription>
                    </Alert>

                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Step 1 — Template
                        </p>
                        <div className="rounded-xl border border-border/80 bg-muted/20 p-4">
                            <p className="text-sm text-muted-foreground">{templateHint}</p>
                            <Button variant="secondary" type="button" className="mt-3 w-full sm:w-auto" asChild>
                                <a href={templateUrl}>
                                    <Download className="mr-2 size-4" />
                                    Download CSV template
                                </a>
                            </Button>
                        </div>
                    </div>

                    <div className="space-y-2">
                        <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Step 2 — Upload
                        </p>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept=".csv,text/csv,text/plain,application/vnd.ms-excel"
                            className="sr-only"
                            id={inputId}
                            onChange={(event) => {
                                pickImportFile(event.target.files?.[0]);
                            }}
                        />
                        <div
                            role="button"
                            tabIndex={0}
                            aria-label="Select or drop a CSV file"
                            className={cn(
                                'group relative rounded-xl border-2 border-dashed border-border bg-background/80 p-6 text-center transition-[color,background-color,border-color,box-shadow] outline-none',
                                'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/35',
                                importDragActive &&
                                    'border-primary bg-primary/6 ring-2 ring-primary/25 ring-offset-2 ring-offset-background',
                                importFile &&
                                    'border-solid border-emerald-500/40 bg-emerald-500/7 hover:bg-emerald-500/9',
                            )}
                            onClick={() => fileInputRef.current?.click()}
                            onKeyDown={(event: KeyboardEvent<HTMLDivElement>) => {
                                if (event.key === 'Enter' || event.key === ' ') {
                                    event.preventDefault();
                                    fileInputRef.current?.click();
                                }
                            }}
                            onDragEnter={(event: DragEvent) => {
                                onImportDrag(event);
                                setImportDragActive(true);
                            }}
                            onDragOver={onImportDrag}
                            onDragLeave={onImportDragLeave}
                            onDrop={onImportDrop}
                        >
                            {importFile ? (
                                <div className="flex flex-col items-center gap-3 sm:flex-row sm:justify-between sm:text-left">
                                    <div className="flex min-w-0 items-start gap-3">
                                        <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                                            <FileSpreadsheet className="size-5" />
                                        </div>
                                        <div className="min-w-0 pt-0.5">
                                            <p className="truncate text-sm font-medium text-foreground">
                                                {importFile.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">Ready to import</p>
                                        </div>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="shrink-0 text-muted-foreground hover:text-foreground"
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            clearImportFile();
                                        }}
                                    >
                                        Remove
                                    </Button>
                                </div>
                            ) : (
                                <div className="flex flex-col items-center gap-2">
                                    <div className="flex size-12 items-center justify-center rounded-xl bg-muted text-muted-foreground transition-colors group-hover:text-foreground">
                                        <Upload className="size-6" />
                                    </div>
                                    <div>
                                        <p className="text-sm font-medium text-foreground">Drop your CSV here</p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            or click to browse —{' '}
                                            <span className="text-foreground/80">.csv</span> files only
                                        </p>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {importMessage ? (
                        <Alert variant="destructive" className="border-destructive/40">
                            <AlertCircle aria-hidden />
                            <AlertDescription>{importMessage}</AlertDescription>
                        </Alert>
                    ) : null}
                </div>

                <DialogFooter className="gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:justify-end">
                    <Button
                        type="button"
                        variant="outline"
                        disabled={importProcessing}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="default"
                        disabled={!importFile || importProcessing}
                        onClick={runImport}
                        aria-busy={importProcessing}
                    >
                        {importProcessing ? (
                            <>
                                <Loader2 className="mr-2 size-4 animate-spin" aria-hidden />
                                Importing…
                            </>
                        ) : (
                            <>
                                <Upload className="mr-2 size-4" aria-hidden />
                                Import
                            </>
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
