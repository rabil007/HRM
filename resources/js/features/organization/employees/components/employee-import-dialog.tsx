import { router } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Loader2,
    RotateCcw,
    Upload,
} from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';
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
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { firstValidationError } from '@/lib/first-validation-error';
import { formatDisplayDate } from '@/lib/format-date';
import { toast } from '@/lib/toast';

type Mapping = Record<string, string | null>;

type RowError = {
    row: number;
    field: string;
    message: string;
};

type PreviewSummary = {
    total: number;
    valid: number;
    invalid: number;
};

type PreviewResponse = {
    headers: string[];
    mapping: Mapping;
    rows: Record<string, unknown>[];
    errors: RowError[];
    summary: PreviewSummary;
};

type Step = 'upload' | 'preview' | 'result';

type ImportResult = {
    created: number;
    skipped: number;
    failed: number;
};

const TEMPLATE_URL = '/organization/employees/import/template';
const PREVIEW_URL = '/organization/employees/import/preview';
const IMPORT_URL = '/organization/employees/import';

const PRIORITY_FIELDS = [
    'employee_no',
    'name',
    'work_email',
    'phone',
    'branch',
    'department',
    'position',
    'hire_date',
];

export function EmployeeImportDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const fileInputRef = useRef<HTMLInputElement | null>(null);
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [step, setStep] = useState<Step>('upload');
    const [isPreviewing, setIsPreviewing] = useState(false);
    const [isImporting, setIsImporting] = useState(false);
    const [result, setResult] = useState<ImportResult | null>(null);

    const reset = useCallback(() => {
        setFile(null);
        setPreview(null);
        setStep('upload');
        setIsPreviewing(false);
        setIsImporting(false);
        setResult(null);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    }, []);

    const handleClose = useCallback(
        (next: boolean) => {
            if (!next) {
                reset();
            }

            onOpenChange(next);
        },
        [onOpenChange, reset],
    );

    const handlePreview = useCallback(
        async (selected?: File) => {
            const target = selected ?? file;

            if (!target) {
                toast.error('Please choose a file to import.');

                return;
            }

            setIsPreviewing(true);

            try {
                const formData = new FormData();
                formData.append('file', target);

                const csrf = document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content;

                const response = await fetch(PREVIEW_URL, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    const data = await response.json().catch(() => null);
                    const message = (data as { message?: string } | null)
                        ?.message;
                    toast.error(message ?? 'Could not preview the file.');

                    return;
                }

                const data = (await response.json()) as PreviewResponse;
                setPreview(data);
                setStep('preview');
            } catch (error) {
                toast.error(
                    error instanceof Error
                        ? error.message
                        : 'Could not preview the file.',
                );
            } finally {
                setIsPreviewing(false);
            }
        },
        [file],
    );

    const handleFileChange = useCallback(
        async (event: React.ChangeEvent<HTMLInputElement>) => {
            const selected = event.target.files?.[0] ?? null;
            setFile(selected);

            if (selected) {
                await handlePreview(selected);
            }
        },
        [handlePreview],
    );

    const handleImport = useCallback(() => {
        if (!file) {
            toast.error('No file to import.');

            return;
        }

        setIsImporting(true);

        const formData = new FormData();
        formData.append('file', file);

        router.post(IMPORT_URL, formData, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                const created = preview ? preview.summary.valid : 0;
                const invalidRows = preview ? preview.summary.invalid : 0;
                setResult({ created, skipped: invalidRows, failed: 0 });
                setStep('result');
            },
            onError: (errors) => {
                toast.error(
                    firstValidationError(
                        errors as Record<string, string | string[]>,
                        'file',
                        'Import failed.',
                    ),
                );
            },
            onFinish: () => setIsImporting(false),
        });
    }, [file, preview]);

    const mappedFields = useMemo(() => {
        if (!preview) {
            return [] as Array<{ field: string; header: string | null }>;
        }

        const entries = Object.entries(preview.mapping);
        const ordered = [
            ...(PRIORITY_FIELDS.map((field) =>
                entries.find(([key]) => key === field),
            ).filter(Boolean) as Array<[string, string | null]>),
            ...entries.filter(([field]) => !PRIORITY_FIELDS.includes(field)),
        ];

        return ordered.map(([field, header]) => ({ field, header }));
    }, [preview]);

    const errorsByRow = useMemo(() => {
        if (!preview) {
            return new Map<number, RowError[]>();
        }

        const map = new Map<number, RowError[]>();
        preview.errors.forEach((error) => {
            const list = map.get(error.row) ?? [];
            list.push(error);
            map.set(error.row, list);
        });

        return map;
    }, [preview]);

    const canImport = preview && preview.summary.valid > 0 && !isImporting;

    return (
        <Dialog open={open} onOpenChange={handleClose}>
            <DialogContent className="max-h-[90vh] w-full overflow-hidden glass-card p-0 sm:max-w-3xl">
                <DialogHeader className="border-b border-border/40 px-6 py-4">
                    <DialogTitle className="text-lg font-semibold">
                        Import employees
                    </DialogTitle>
                    <DialogDescription className="text-sm text-muted-foreground/80">
                        Upload a CSV or Excel file to bulk-create employees and
                        their primary contracts.
                    </DialogDescription>
                </DialogHeader>

                <div className="px-6 py-4">
                    <Stepper step={step} />
                </div>

                <ScrollArea className="max-h-[60vh] px-6">
                    {step === 'upload' ? (
                        <UploadStep
                            file={file}
                            isPreviewing={isPreviewing}
                            inputRef={fileInputRef}
                            onFileChange={handleFileChange}
                            onChoose={() => fileInputRef.current?.click()}
                        />
                    ) : null}

                    {step === 'preview' && preview ? (
                        <PreviewStep
                            preview={preview}
                            mappedFields={mappedFields}
                            errorsByRow={errorsByRow}
                            file={file}
                            onReUpload={() => {
                                setStep('upload');
                                setPreview(null);
                            }}
                        />
                    ) : null}

                    {step === 'result' && result ? (
                        <ResultStep result={result} />
                    ) : null}
                </ScrollArea>

                <DialogFooter className="mt-2 flex flex-row items-center justify-between gap-2 border-t border-border/40 px-6 py-4 sm:flex-row sm:justify-between">
                    <a
                        href={TEMPLATE_URL}
                        onClick={() => {
                            // #region agent log
                            fetch('http://127.0.0.1:7482/ingest/d3b1b2aa-09dd-440b-8cc6-35eab404e1c8',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'351a82'},body:JSON.stringify({sessionId:'351a82',runId:'sponsor-ui',hypothesisId:'C',location:'employee-import-dialog.tsx:download',message:'dialog download template clicked',data:{templateUrl:TEMPLATE_URL,hasTemplateId:TEMPLATE_URL.includes('template_id')},timestamp:Date.now()})}).catch(()=>{});
                            // #endregion
                        }}
                        className="inline-flex items-center gap-2 text-sm text-muted-foreground/80 hover:text-foreground"
                    >
                        <Download className="h-4 w-4" /> Download template
                    </a>

                    <div className="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => handleClose(false)}
                        >
                            {step === 'result' ? 'Close' : 'Cancel'}
                        </Button>

                        {step === 'preview' ? (
                            <Button
                                type="button"
                                onClick={handleImport}
                                disabled={!canImport}
                                className="rounded-xl"
                            >
                                {isImporting ? (
                                    <>
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />{' '}
                                        Importing...
                                    </>
                                ) : (
                                    <>
                                        <Upload className="mr-2 h-4 w-4" />{' '}
                                        Import {preview?.summary.valid ?? 0} row
                                        {preview?.summary.valid === 1
                                            ? ''
                                            : 's'}
                                    </>
                                )}
                            </Button>
                        ) : null}

                        {step === 'result' ? (
                            <Button
                                type="button"
                                onClick={reset}
                                variant="outline"
                                className="rounded-xl"
                            >
                                <RotateCcw className="mr-2 h-4 w-4" /> Import
                                another file
                            </Button>
                        ) : null}
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Stepper({ step }: { step: Step }) {
    const steps: Array<{ key: Step; label: string }> = [
        { key: 'upload', label: 'Upload' },
        { key: 'preview', label: 'Preview & validate' },
        { key: 'result', label: 'Result' },
    ];

    const activeIndex = steps.findIndex((s) => s.key === step);

    return (
        <ol className="flex items-center gap-3 text-xs">
            {steps.map((item, index) => {
                const isDone = index < activeIndex;
                const isActive = index === activeIndex;

                return (
                    <li key={item.key} className="flex items-center gap-3">
                        <span
                            className={`flex h-6 w-6 items-center justify-center rounded-full border text-[11px] font-semibold ${
                                isActive
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : isDone
                                      ? 'border-primary/40 bg-primary/15 text-primary'
                                      : 'border-border/60 text-muted-foreground/70'
                            }`}
                        >
                            {index + 1}
                        </span>
                        <span
                            className={
                                isActive
                                    ? 'font-semibold text-foreground'
                                    : isDone
                                      ? 'text-foreground/80'
                                      : 'text-muted-foreground/70'
                            }
                        >
                            {item.label}
                        </span>
                        {index < steps.length - 1 ? (
                            <span className="h-px w-8 bg-border/60" />
                        ) : null}
                    </li>
                );
            })}
        </ol>
    );
}

function UploadStep({
    file,
    isPreviewing,
    inputRef,
    onFileChange,
    onChoose,
}: {
    file: File | null;
    isPreviewing: boolean;
    inputRef: React.RefObject<HTMLInputElement | null>;
    onFileChange: (event: React.ChangeEvent<HTMLInputElement>) => void;
    onChoose: () => void;
}) {
    return (
        <div className="space-y-4 py-2">
            <button
                type="button"
                onClick={onChoose}
                className="flex w-full flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-border/60 px-8 py-12 transition-colors hover:bg-accent/30"
            >
                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                    {isPreviewing ? (
                        <Loader2 className="h-5 w-5 animate-spin" />
                    ) : (
                        <FileSpreadsheet className="h-5 w-5" />
                    )}
                </div>
                <div className="space-y-1 text-center">
                    <p className="text-sm font-medium">
                        {file
                            ? file.name
                            : 'Click to choose a CSV or Excel file'}
                    </p>
                    <p className="text-xs text-muted-foreground/70">
                        Accepted formats: .csv, .xlsx, .xls (max 10 MB).
                    </p>
                </div>
            </button>

            <input
                ref={inputRef}
                type="file"
                accept=".csv,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                hidden
                onChange={onFileChange}
            />

            <div className="space-y-1 rounded-xl border border-dashed border-border/60 px-4 py-3 text-xs text-muted-foreground/80">
                <p className="text-sm font-medium text-foreground">
                    Tips for a clean import
                </p>
                <ul className="list-inside list-disc space-y-0.5">
                    <li>
                        <strong>employee_no</strong> and <strong>name</strong>{' '}
                        are required.
                    </li>
                    <li>
                        Branch / Department / Position / Manager are matched by
                        name.
                    </li>
                    <li>
                        Manager can also be matched by their employee number.
                    </li>
                    <li>
                        Dates use the YYYY-MM-DD format. Excel-style dates are
                        auto-converted.
                    </li>
                </ul>
            </div>
        </div>
    );
}

function PreviewStep({
    preview,
    mappedFields,
    errorsByRow,
    file,
    onReUpload,
}: {
    preview: PreviewResponse;
    mappedFields: Array<{ field: string; header: string | null }>;
    errorsByRow: Map<number, RowError[]>;
    file: File | null;
    onReUpload: () => void;
}) {
    const requiredFields = ['employee_no', 'name'];
    const unmappedRequired = requiredFields.filter(
        (field) => !preview.mapping[field],
    );

    return (
        <div className="space-y-5 py-2">
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <Badge
                    variant="outline"
                    className="border-primary/30 bg-primary/10 text-primary"
                >
                    {preview.summary.total} row
                    {preview.summary.total === 1 ? '' : 's'}
                </Badge>
                <Badge
                    variant="outline"
                    className="border-emerald-500/30 bg-emerald-500/10 text-emerald-500 dark:text-emerald-300"
                >
                    <CheckCircle2 className="mr-1 h-3 w-3" />{' '}
                    {preview.summary.valid} valid
                </Badge>
                {preview.summary.invalid > 0 ? (
                    <Badge
                        variant="outline"
                        className="border-destructive/30 bg-destructive/10 text-destructive"
                    >
                        <AlertCircle className="mr-1 h-3 w-3" />{' '}
                        {preview.summary.invalid} invalid
                    </Badge>
                ) : null}
                {file ? (
                    <span className="ml-auto text-xs text-muted-foreground/70">
                        {file.name} ·{' '}
                        <button
                            type="button"
                            onClick={onReUpload}
                            className="underline hover:text-foreground"
                        >
                            change file
                        </button>
                    </span>
                ) : null}
            </div>

            <div className="grid gap-2 sm:grid-cols-2">
                <div className="rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-4 py-3">
                    <div className="text-2xl font-bold text-emerald-500 tabular-nums">
                        {preview.summary.valid}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        {preview.summary.valid === 1 ? 'Employee' : 'Employees'}{' '}
                        will be created
                    </div>
                </div>
                {preview.summary.invalid > 0 ? (
                    <div className="rounded-xl border border-destructive/20 bg-destructive/5 px-4 py-3">
                        <div className="text-lg font-semibold text-destructive tabular-nums">
                            {preview.summary.invalid}
                        </div>
                        <div className="text-xs text-destructive/90">
                            Row{preview.summary.invalid === 1 ? '' : 's'}{' '}
                            skipped (errors)
                        </div>
                    </div>
                ) : (
                    <div className="flex items-center rounded-xl border border-border/60 bg-background/40 px-4 py-3 text-xs text-muted-foreground">
                        No rows skipped
                    </div>
                )}
            </div>

            {unmappedRequired.length > 0 ? (
                <div className="flex items-start gap-2 rounded-xl border border-destructive/30 bg-destructive/10 px-4 py-3 text-xs text-destructive">
                    <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <p className="font-semibold">
                            Missing required columns
                        </p>
                        <p className="opacity-90">
                            Add columns for:{' '}
                            <strong>{unmappedRequired.join(', ')}</strong>. Use
                            the template if you're unsure of the exact names.
                        </p>
                    </div>
                </div>
            ) : null}

            <section>
                <header className="mb-2 text-sm font-semibold text-foreground">
                    Detected column mapping
                </header>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {mappedFields.map(({ field, header }) => {
                        const isRequired = requiredFields.includes(field);
                        const isMapped = Boolean(header);

                        return (
                            <div
                                key={field}
                                className={`flex items-center justify-between rounded-lg border px-3 py-2 text-xs ${
                                    isMapped
                                        ? 'border-border/60 bg-background/40'
                                        : isRequired
                                          ? 'border-destructive/30 bg-destructive/5'
                                          : 'border-border/40 bg-background/20 opacity-60'
                                }`}
                            >
                                <div className="flex items-center gap-2">
                                    <span className="font-medium text-foreground">
                                        {field}
                                    </span>
                                    {isRequired ? (
                                        <span className="text-[10px] text-destructive uppercase">
                                            required
                                        </span>
                                    ) : null}
                                </div>
                                <span
                                    className={
                                        isMapped
                                            ? 'text-muted-foreground/80'
                                            : 'text-muted-foreground/60'
                                    }
                                >
                                    {header ?? '— not mapped —'}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </section>

            <section>
                <header className="mb-2 text-sm font-semibold text-foreground">
                    Rows
                </header>
                <div className="max-h-[min(50vh,24rem)] overflow-auto rounded-xl border border-border/60">
                    <table className="min-w-full text-left text-xs">
                        <thead className="sticky top-0 z-10 bg-background/95 text-muted-foreground/80 backdrop-blur-sm">
                            <tr>
                                <th className="px-3 py-2 font-medium">Row</th>
                                <th className="px-3 py-2 font-medium">
                                    Employee no
                                </th>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">
                                    Date of hire
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {preview.rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-3 py-6 text-center text-muted-foreground/70"
                                    >
                                        No rows found in the file.
                                    </td>
                                </tr>
                            ) : (
                                preview.rows.map((row, index) => {
                                    const rowNumber = index + 2;
                                    const rowErrors =
                                        errorsByRow.get(rowNumber);

                                    return (
                                        <tr
                                            key={rowNumber}
                                            className={`border-t border-border/40 ${
                                                rowErrors
                                                    ? 'bg-destructive/5'
                                                    : ''
                                            }`}
                                        >
                                            <td className="px-3 py-2 text-muted-foreground/70">
                                                {rowNumber}
                                            </td>
                                            <td className="px-3 py-2 font-medium">
                                                {stringy(row.employee_no)}
                                            </td>
                                            <td className="px-3 py-2">
                                                {stringy(row.name) || '—'}
                                            </td>
                                            <td className="px-3 py-2 text-muted-foreground/80">
                                                {formatDisplayDate(
                                                    stringy(row.hire_date) ||
                                                        null,
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                {rowErrors ? (
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span className="inline-flex cursor-help">
                                                                <Badge
                                                                    variant="outline"
                                                                    className="border-destructive/30 bg-destructive/10 text-destructive"
                                                                >
                                                                    {
                                                                        rowErrors.length
                                                                    }{' '}
                                                                    error
                                                                    {rowErrors.length ===
                                                                    1
                                                                        ? ''
                                                                        : 's'}
                                                                </Badge>
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent
                                                            side="left"
                                                            align="start"
                                                            className="max-w-sm text-left font-normal"
                                                        >
                                                            <ul className="list-inside list-disc space-y-1">
                                                                {rowErrors.map(
                                                                    (e, i) => (
                                                                        <li
                                                                            key={`${e.field}-${i}`}
                                                                        >
                                                                            <span className="font-medium">
                                                                                {
                                                                                    e.field
                                                                                }
                                                                                :
                                                                            </span>{' '}
                                                                            {
                                                                                e.message
                                                                            }
                                                                        </li>
                                                                    ),
                                                                )}
                                                            </ul>
                                                        </TooltipContent>
                                                    </Tooltip>
                                                ) : (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-emerald-500/30 bg-emerald-500/10 text-emerald-500 dark:text-emerald-300"
                                                    >
                                                        Ready
                                                    </Badge>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </section>

            {preview.errors.length > 0 ? (
                <section>
                    <header className="mb-2 text-sm font-semibold text-foreground">
                        Validation errors ({preview.errors.length})
                    </header>
                    <div className="max-h-60 overflow-auto rounded-xl border border-border/60">
                        <table className="min-w-full text-left text-xs">
                            <thead className="sticky top-0 z-10 bg-background/95 text-muted-foreground/80 backdrop-blur-sm">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Row
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Field
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Message
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {preview.errors.map((error, index) => (
                                    <tr
                                        key={`${error.row}-${index}`}
                                        className="border-t border-border/40"
                                    >
                                        <td className="px-3 py-2 text-muted-foreground/70">
                                            {error.row}
                                        </td>
                                        <td className="px-3 py-2 font-medium text-foreground">
                                            {error.field}
                                        </td>
                                        <td className="px-3 py-2 text-destructive/90">
                                            {error.message}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            ) : null}
        </div>
    );
}

function ResultStep({ result }: { result: ImportResult }) {
    return (
        <div className="space-y-4 py-6 text-center">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-500 dark:text-emerald-300">
                <CheckCircle2 className="h-7 w-7" />
            </div>
            <div className="space-y-1">
                <h3 className="text-lg font-semibold">Import finished</h3>
                <p className="text-sm text-muted-foreground/80">
                    Created{' '}
                    <strong className="text-foreground">
                        {result.created}
                    </strong>{' '}
                    employee
                    {result.created === 1 ? '' : 's'}.
                    {result.skipped > 0 ? (
                        <>
                            {' '}
                            Skipped{' '}
                            <strong className="text-foreground">
                                {result.skipped}
                            </strong>{' '}
                            invalid row
                            {result.skipped === 1 ? '' : 's'}.
                        </>
                    ) : null}
                </p>
            </div>
        </div>
    );
}

function stringy(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}
