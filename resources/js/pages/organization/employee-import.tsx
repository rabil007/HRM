import { Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Loader2,
    Upload,
    X,
} from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
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
    field_options: ImportFieldOption[];
    max_rows: number;
};

type ImportFieldOption = {
    field: string;
    label: string;
    required: boolean;
    sensitive: boolean;
    permission: string | null;
    allowed: boolean;
};

type TemplateOption = {
    id: number;
    name: string;
    description: string | null;
    is_default: boolean;
};

type Props = {
    template_url: string;
    preview_url: string;
    import_url: string;
    field_options: ImportFieldOption[];
    max_rows: number;
    templates: TemplateOption[];
    default_template_id: number | null;
};

const REQUIRED_FIELDS = ['employee_no', 'name'];
const PRIORITY_FIELDS = [
    'employee_no',
    'name',
    'work_email',
    'phone',
    'branch',
    'department',
    'position',
    'contract_type',
    'start_date',
];

export default function EmployeeImport({ template_url, preview_url, import_url, field_options, max_rows, templates, default_template_id }: Props) {
    const inputRef = useRef<HTMLInputElement | null>(null);
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [mapping, setMapping] = useState<Mapping>({});
    const [isDragging, setIsDragging] = useState(false);
    const [isPreviewing, setIsPreviewing] = useState(false);
    const [isImporting, setIsImporting] = useState(false);
    const [result, setResult] = useState<{ created: number; skipped: number; failed: number } | null>(null);
    const [selectedTemplateId, setSelectedTemplateId] = useState<number | null>(default_template_id);

    const templateDownloadUrl = useMemo(() => {
        if (!selectedTemplateId) {
            return template_url;
        }

        const separator = template_url.includes('?') ? '&' : '?';

        return `${template_url}${separator}template_id=${selectedTemplateId}`;
    }, [selectedTemplateId, template_url]);

    const errorsByRow = useMemo(() => {
        const map = new Map<number, RowError[]>();

        preview?.errors.forEach((error) => {
            const current = map.get(error.row) ?? [];
            current.push(error);
            map.set(error.row, current);
        });

        return map;
    }, [preview]);

    const mappedFields = useMemo(() => {
        if (!preview) {
            return [] as Array<ImportFieldOption & { header: string | null }>;
        }

        const fields = preview.field_options ?? field_options;
        const ordered = [
            ...(PRIORITY_FIELDS.map((field) => fields.find((option) => option.field === field)).filter(Boolean) as ImportFieldOption[]),
            ...fields.filter((option) => !PRIORITY_FIELDS.includes(option.field)),
        ];

        return ordered.map((option) => ({ ...option, header: mapping[option.field] ?? null }));
    }, [field_options, mapping, preview]);

    const unmappedRequired = useMemo(() => {
        if (!preview) {
            return [];
        }

        return REQUIRED_FIELDS.filter((field) => !mapping[field]);
    }, [mapping, preview]);

    const reset = useCallback(() => {
        setFile(null);
        setPreview(null);
        setMapping({});
        setResult(null);
        setIsDragging(false);

        if (inputRef.current) {
            inputRef.current.value = '';
        }
    }, []);

    const previewFile = useCallback(async (selected: File, selectedMapping?: Mapping, templateId?: number | null) => {
        setFile(selected);
        setResult(null);
        setIsPreviewing(true);

        try {
            const formData = new FormData();
            formData.append('file', selected);
            formData.append('onboarding_template_id', String(templateId ?? selectedTemplateId ?? ''));
            Object.entries(selectedMapping ?? {}).forEach(([field, header]) => {
                formData.append(`mapping[${field}]`, header ?? '');
            });

            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

            const response = await fetch(preview_url, {
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
                const message = (data as { message?: string } | null)?.message;
                toast.error(message ?? 'Could not preview the file.');

                return;
            }

            const data = (await response.json()) as PreviewResponse;
            setPreview(data);
            setMapping(data.mapping);
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Could not preview the file.');
        } finally {
            setIsPreviewing(false);
        }
    }, [preview_url, selectedTemplateId]);

    const selectFile = useCallback((selected: File | null) => {
        if (!selected) {
            return;
        }

        previewFile(selected);
    }, [previewFile]);

    const importFile = useCallback(async () => {
        if (!file || !preview) {
            toast.error('Choose and test a file first.');

            return;
        }

        setIsImporting(true);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('onboarding_template_id', String(selectedTemplateId ?? ''));
        Object.entries(mapping).forEach(([field, header]) => {
            formData.append(`mapping[${field}]`, header ?? '');
        });

        try {
            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

            const response = await fetch(import_url, {
                method: 'POST',
                body: formData,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                },
                credentials: 'same-origin',
            });

            const data = await response.json().catch(() => null) as {
                created?: number;
                skipped?: number[];
                failed?: unknown[];
                message?: string;
            } | null;

            if (!response.ok) {
                toast.error(data?.message ?? 'Import failed.');

                return;
            }

            setResult({
                created: data?.created ?? preview.summary.valid,
                skipped: data?.skipped?.length ?? preview.summary.invalid,
                failed: data?.failed?.length ?? 0,
            });
            toast.success(data?.message ?? 'Import completed.');
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Import failed.');
        } finally {
            setIsImporting(false);
        }
    }, [file, import_url, mapping, preview, selectedTemplateId]);

    const handleMappingChange = useCallback((field: string, header: string) => {
        if (!file) {
            return;
        }

        const next = {
            ...mapping,
            [field]: header || null,
        };

        setMapping(next);
        previewFile(file, next);
    }, [file, mapping, previewFile]);

    return (
        <>
            <Head title="Import employees" />

            <Main>
                <PageHeader
                    title="Import employees"
                    description="Upload a CSV or Excel file, review the detected mapping, then import valid employee rows."
                    right={
                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="outline" className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10 h-11 px-4" asChild>
                                <Link href="/organization/employees">Cancel</Link>
                            </Button>
                            <Button variant="secondary" className="glass-card rounded-xl h-11 px-4 hover:bg-accent" asChild>
                                <a href={selectedTemplateId ? templateDownloadUrl : template_url}>
                                    <Download className="mr-2 h-4 w-4" />
                                    Template
                                </a>
                            </Button>
                            <Button className="rounded-xl shadow-lg shadow-primary/20 h-11 px-5" disabled={!selectedTemplateId || !preview || preview.summary.valid === 0 || isImporting} onClick={importFile}>
                                {isImporting ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Upload className="mr-2 h-4 w-4" />}
                                Import
                                {preview && preview.summary.valid > 0
                                    ? ` ${preview.summary.valid} ${preview.summary.valid === 1 ? 'row' : 'rows'}`
                                    : ''}
                            </Button>
                        </div>
                    }
                />

                <div className="mb-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <Badge variant="outline" className="border-white/10 bg-white/5 text-muted-foreground">CSV</Badge>
                    <Badge variant="outline" className="border-white/10 bg-white/5 text-muted-foreground">XLSX</Badge>
                    <Badge variant="outline" className="border-white/10 bg-white/5 text-muted-foreground">XLS</Badge>
                    <span>Maximum file size 10 MB · Maximum {max_rows} rows</span>
                </div>

                {!preview && !result ? (
                    <div className="space-y-4">
                        <Card className="glass-card">
                            <CardContent className="p-5">
                                <div className="mb-1 flex items-center gap-2">
                                    <label htmlFor="onboarding-template" className="text-sm font-semibold text-foreground">
                                        Onboarding Template
                                    </label>
                                    <span className="rounded bg-destructive/10 px-1.5 py-0.5 text-[10px] font-medium text-destructive">Required</span>
                                </div>
                                <p className="mb-3 text-xs text-muted-foreground">
                                    All imported employees will be assigned this template. The downloadable import template and column mapping only include fields configured on that template (plus employee no and name).
                                </p>
                                {templates.length === 0 ? (
                                    <div className="rounded-lg border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-500">
                                        No onboarding templates found. Please create one before importing employees.
                                    </div>
                                ) : (
                                    <AppSelect
                                        value={String(selectedTemplateId ?? '')}
                                        onValueChange={(v) => setSelectedTemplateId(v ? Number(v) : null)}
                                        variant="card"
                                        placeholder="Select a template…"
                                        className="max-w-sm"
                                    >
                                        <AppSelectItem value="">Select a template…</AppSelectItem>
                                        {templates.map((t) => (
                                            <AppSelectItem key={t.id} value={String(t.id)}>
                                                {t.name}{t.is_default ? ' (Default)' : ''}
                                            </AppSelectItem>
                                        ))}
                                    </AppSelect>
                                )}
                            </CardContent>
                        </Card>

                        <Card className="glass-card">
                            <CardContent className="p-6">
                                <div
                                    onDragOver={(event) => {
                                        event.preventDefault();
                                        setIsDragging(true);
                                    }}
                                    onDragLeave={() => setIsDragging(false)}
                                    onDrop={(event) => {
                                        event.preventDefault();
                                        setIsDragging(false);

                                        if (selectedTemplateId) {
selectFile(event.dataTransfer.files?.[0] ?? null);
} else {
toast.error('Please select an onboarding template first.');
}
                                    }}
                                    className={`flex min-h-[380px] w-full flex-col items-center justify-center rounded-2xl border-2 border-dashed px-8 py-12 text-center transition-colors ${
                                        !selectedTemplateId ? 'cursor-not-allowed border-border/30 bg-background/10 opacity-50' :
                                        isDragging ? 'border-primary bg-primary/10' : 'border-border/60 bg-background/30 hover:bg-accent/20'
                                    }`}
                                >
                                    <button
                                        type="button"
                                        disabled={!selectedTemplateId}
                                        onClick={() => selectedTemplateId && inputRef.current?.click()}
                                        className="group flex flex-col items-center disabled:cursor-not-allowed"
                                    >
                                        <div className="mb-4 flex h-24 w-24 items-center justify-center rounded-3xl border border-white/10 bg-primary/10 text-primary shadow-xl shadow-primary/10">
                                            {isPreviewing ? <Loader2 className="h-9 w-9 animate-spin" /> : <FileSpreadsheet className="h-10 w-10" />}
                                        </div>
                                        <h1 className="text-xl font-bold text-foreground">Drop or upload a file to import</h1>
                                        <p className="mt-3 max-w-md text-sm text-muted-foreground">
                                            {selectedTemplateId ? 'Excel files are recommended because formatting is automatic. CSV files are also supported.' : 'Select an onboarding template above to enable file upload.'}
                                        </p>
                                        {selectedTemplateId ? <p className="mt-2 text-xs text-muted-foreground/70">Click this area or drag a file here.</p> : null}
                                    </button>

                                    {selectedTemplateId ? (
                                        <div className="mt-5 flex flex-wrap justify-center gap-2">
                                            <Button variant="secondary" className="rounded-xl" asChild>
                                                <a href={templateDownloadUrl}>
                                                    <Download className="mr-2 h-4 w-4" />
                                                    Import Template for Employees
                                                </a>
                                            </Button>
                                            <Button type="button" variant="outline" className="rounded-xl border-white/5 bg-white/5 hover:bg-white/10" onClick={() => inputRef.current?.click()}>
                                                <Upload className="mr-2 h-4 w-4" />
                                                Upload Data File
                                            </Button>
                                        </div>
                                    ) : null}

                                    <input
                                        ref={inputRef}
                                        type="file"
                                        className="hidden"
                                        accept=".csv,.txt,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                        onChange={(event) => selectFile(event.target.files?.[0] ?? null)}
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                ) : null}

                {preview ? (
                    <div className="grid gap-6 xl:grid-cols-[300px_1fr]">
                        <Card className="glass-card h-fit">
                            <CardContent className="space-y-6 p-5">
                                <section>
                                    <h2 className="mb-3 text-sm font-semibold text-foreground">Data to import</h2>
                                    <div className="rounded-xl border border-white/10 bg-background/40 p-3 text-xs">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0 space-y-1">
                                                <div className="truncate font-medium text-foreground">{file?.name ?? 'Selected file'}</div>
                                                <div className="text-muted-foreground">{preview.summary.total} data row(s) in file</div>
                                            </div>
                                            <button type="button" onClick={reset} className="rounded-lg p-1 text-muted-foreground hover:bg-accent hover:text-foreground">
                                                <X className="h-4 w-4" />
                                            </button>
                                        </div>
                                        <div className="mt-3 space-y-2 border-t border-white/10 pt-3">
                                            <div className="rounded-lg border border-emerald-500/25 bg-emerald-500/10 px-3 py-2.5">
                                                <div className="text-2xl font-bold tabular-nums text-emerald-500">
                                                    {preview.summary.valid}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {preview.summary.valid === 1 ? 'Employee' : 'Employees'} will be created
                                                </div>
                                            </div>
                                            {preview.summary.invalid > 0 ? (
                                                <div className="rounded-lg border border-destructive/20 bg-destructive/5 px-3 py-2">
                                                    <div className="text-lg font-semibold tabular-nums text-destructive">
                                                        {preview.summary.invalid}
                                                    </div>
                                                    <div className="text-xs text-destructive/90">
                                                        Row{preview.summary.invalid === 1 ? '' : 's'} skipped (validation errors)
                                                    </div>
                                                </div>
                                            ) : null}
                                        </div>
                                        <label className="mt-3 flex items-center gap-2 text-emerald-500">
                                            <input type="checkbox" checked readOnly className="rounded" />
                                            Use first row as header
                                        </label>
                                    </div>
                                </section>

                                <section className="space-y-2 text-xs">
                                    <h2 className="text-sm font-semibold text-foreground">Template</h2>
                                    <div className="rounded-lg border border-white/10 bg-background/40 px-3 py-2">
                                        <div className="font-medium text-foreground">
                                            {templates.find((t) => t.id === selectedTemplateId)?.name ?? '—'}
                                        </div>
                                        <div className="mt-0.5 text-muted-foreground">Onboarding template</div>
                                    </div>
                                </section>

                                <section className="space-y-2 text-xs">
                                    <h2 className="text-sm font-semibold text-foreground">Help</h2>
                                    <a href={templateDownloadUrl} className="block text-primary hover:underline">Import Template for Employees</a>
                                    <span className="block text-muted-foreground">
                                        Required fields to map are <strong className="text-foreground">Employee No</strong> and{' '}
                                        <strong className="text-foreground">Name</strong>. If you leave Contract type or Start date
                                        as &quot;Do not import&quot;, new employees get unlimited contract and start date today.
                                    </span>
                                    <span className="block text-muted-foreground">Use exact column names for the cleanest mapping.</span>
                                </section>
                            </CardContent>
                        </Card>

                        <div className="space-y-4 overflow-hidden">
                            <Card className="glass-card overflow-hidden">
                                <div className="overflow-x-auto">
                                    <div className="min-w-[980px]">
                                <div className="grid grid-cols-[1fr_1.8fr_1.8fr_1.8fr] border-b border-white/10 bg-muted/30 px-4 py-3 text-xs font-semibold text-muted-foreground">
                                    <div>File Column</div>
                                    <div>Preview</div>
                                    <div>Employee Field</div>
                                    <div>Comments</div>
                                </div>

                                <div className="divide-y divide-white/10">
                                    {mappedFields.map(({ field, label, header, required, sensitive, allowed, permission }) => {
                                        const isMapped = Boolean(header);
                                        const relatedErrors = preview.errors.filter((error) => error.field === field);

                                        return (
                                            <div key={field} className="grid grid-cols-[1fr_1.8fr_1.8fr_1.8fr] items-center px-4 py-3 text-xs">
                                                <div>
                                                    <div className="font-semibold text-foreground">{label}</div>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-muted-foreground">
                                                        <span>{field}</span>
                                                        {required ? (
                                                            <span className="rounded bg-rose-500/10 px-1.5 py-0.5 text-[10px] text-rose-400">Required</span>
                                                        ) : null}
                                                        {sensitive ? (
                                                            <span className="rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] text-amber-500">Sensitive</span>
                                                        ) : null}
                                                    </div>
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {preview.rows.slice(0, 2).map((row, index) => (
                                                        <div key={index} className="truncate">{stringy(row[field]) || '—'}</div>
                                                    ))}
                                                </div>
                                                <div>
                                                    <AppSelect
                                                        value={header ?? ''}
                                                        onValueChange={(v) => handleMappingChange(field, v)}
                                                        disabled={!allowed || isPreviewing}
                                                        variant="card"
                                                        placeholder={allowed ? 'Do not import' : 'Permission required'}
                                                        size="sm"
                                                    >
                                                        <AppSelectItem value="">{allowed ? 'Do not import' : 'Permission required'}</AppSelectItem>
                                                        {preview.headers.map((candidate) => (
                                                            <AppSelectItem key={`${field}-${candidate}`} value={candidate}>
                                                                {candidate}
                                                            </AppSelectItem>
                                                        ))}
                                                    </AppSelect>
                                                </div>
                                                <div>
                                                    {relatedErrors.length > 0 ? (
                                                        <div className="space-y-1 text-destructive">
                                                            {relatedErrors.slice(0, 2).map((error, index) => (
                                                                <div key={index}>Row {error.row}: {error.message}</div>
                                                            ))}
                                                        </div>
                                                    ) : isMapped ? (
                                                        <span className="text-emerald-500">Ready</span>
                                                    ) : !allowed ? (
                                                        <span className="text-amber-500">
                                                            Requires {permission}
                                                        </span>
                                                    ) : required ? (
                                                        <span className="text-amber-500">Required</span>
                                                    ) : (
                                                        <span className="text-muted-foreground">Ignored</span>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                    </div>
                                </div>
                            </Card>

                            <Card className="glass-card">
                                <CardContent className="p-4">
                                    <div className="mb-3 flex flex-wrap items-center gap-2">
                                        <Badge className="border-primary/30 bg-primary/15 font-semibold text-primary">
                                            Will create {preview.summary.valid}
                                        </Badge>
                                        <Badge className="border-white/10 bg-white/5 text-foreground">{preview.summary.total} total</Badge>
                                        <Badge className="border-emerald-500/20 bg-emerald-500/10 text-emerald-500">{preview.summary.valid} valid</Badge>
                                        {preview.summary.invalid > 0 ? (
                                            <Badge className="border-destructive/20 bg-destructive/10 text-destructive">{preview.summary.invalid} invalid</Badge>
                                        ) : null}
                                        {unmappedRequired.length > 0 ? (
                                            <Badge className="border-amber-500/20 bg-amber-500/10 text-amber-500">
                                                Missing: {unmappedRequired.join(', ')}
                                            </Badge>
                                        ) : null}
                                    </div>

                                    <div className="max-h-[min(70vh,32rem)] overflow-auto rounded-xl border border-white/10">
                                        <table className="w-full text-left text-xs">
                                            <thead className="sticky top-0 z-10 bg-muted/95 text-muted-foreground backdrop-blur-sm">
                                                <tr>
                                                    <th className="px-3 py-2">Row</th>
                                                    <th className="px-3 py-2">Employee No</th>
                                                    <th className="px-3 py-2">Name</th>
                                                    <th className="px-3 py-2">Contract</th>
                                                    <th className="px-3 py-2">Start Date</th>
                                                    <th className="px-3 py-2">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-white/10">
                                                {preview.rows.map((row, index) => {
                                                    const rowNumber = index + 2;
                                                    const rowErrors = errorsByRow.get(rowNumber);

                                                    return (
                                                        <tr key={rowNumber} className={rowErrors ? 'bg-destructive/5' : ''}>
                                                            <td className="px-3 py-2 text-muted-foreground">{rowNumber}</td>
                                                            <td className="px-3 py-2 font-medium text-foreground">{stringy(row.employee_no) || '—'}</td>
                                                            <td className="px-3 py-2 text-muted-foreground">{stringy(row.name) || '—'}</td>
                                                            <td className="px-3 py-2 text-muted-foreground">{stringy(row.contract_type) || '—'}</td>
                                                            <td className="px-3 py-2 text-muted-foreground">{stringy(row.start_date) || '—'}</td>
                                                            <td className="px-3 py-2">
                                                                {rowErrors ? (
                                                                    <Tooltip>
                                                                        <TooltipTrigger asChild>
                                                                            <button
                                                                                type="button"
                                                                                className="inline-flex cursor-help items-center gap-1 rounded-md border-0 bg-transparent p-0 text-left text-xs text-destructive outline-none hover:opacity-90 focus-visible:ring-2 focus-visible:ring-ring"
                                                                            >
                                                                                <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                                                                                {rowErrors.length} error{rowErrors.length === 1 ? '' : 's'}
                                                                            </button>
                                                                        </TooltipTrigger>
                                                                        <TooltipContent
                                                                            side="left"
                                                                            align="start"
                                                                            className="max-w-sm text-left font-normal"
                                                                        >
                                                                            <ul className="list-inside list-disc space-y-1">
                                                                                {rowErrors.map((e, i) => (
                                                                                    <li key={`${e.field}-${i}`}>
                                                                                        <span className="font-medium">{e.field}:</span>{' '}
                                                                                        {e.message}
                                                                                    </li>
                                                                                ))}
                                                                            </ul>
                                                                        </TooltipContent>
                                                                    </Tooltip>
                                                                ) : (
                                                                    <span className="inline-flex items-center gap-1 text-emerald-500">
                                                                        <CheckCircle2 className="h-3.5 w-3.5" />
                                                                        Ready
                                                                    </span>
                                                                )}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                ) : null}

                {result ? (
                    <div className="fixed bottom-6 right-6 z-30 w-full max-w-sm rounded-xl border border-emerald-500/20 bg-card p-4 shadow-2xl shadow-black/20">
                        <div className="flex items-start gap-3">
                            <div className="rounded-full bg-emerald-500/10 p-2 text-emerald-500">
                                <CheckCircle2 className="h-5 w-5" />
                            </div>
                            <div>
                                <div className="font-semibold text-foreground">Import finished</div>
                                <div className="mt-1 text-sm text-muted-foreground">
                                    Created {result.created} employee{result.created === 1 ? '' : 's'}.
                                    {result.skipped ? ` Skipped ${result.skipped} invalid row${result.skipped === 1 ? '' : 's'}.` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                ) : null}
            </Main>
        </>
    );
}

function stringy(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}
