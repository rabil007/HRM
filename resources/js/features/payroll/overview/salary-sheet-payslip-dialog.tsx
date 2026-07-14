import { FileSpreadsheet, Loader2, Upload } from 'lucide-react';
import type { DragEvent, ReactElement } from 'react';
import { useMemo, useRef, useState } from 'react';
import {
    fromSalarySheet,
    previewSalarySheet,
} from '@/actions/App/Http/Controllers/Payroll/PayslipController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

type SalarySheetRow = {
    row: number;
    employee_no: string;
    name: string;
    designation: string;
    standby_days: number;
    onsite_days: number;
    basic_salary: number;
    supplim_allow: number;
    site_allow: number;
    standby_pay: number;
    onsite_pay: number;
    add_ded: number;
    overtime_pay: number;
    total_salary: number;
};

type PreviewResponse = {
    rows: SalarySheetRow[];
    summary: { total: number };
};

type SalarySheetPayslipDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

function isSpreadsheetLike(file: File): boolean {
    const name = file.name.toLowerCase();

    return (
        name.endsWith('.xlsx') ||
        name.endsWith('.xls') ||
        file.type.includes('spreadsheet') ||
        file.type.includes('excel')
    );
}

function parseContentDispositionFilename(header: string | null): string | null {
    if (!header) {
        return null;
    }

    const utfMatch = header.match(/filename\*=UTF-8''([^;]+)/i);

    if (utfMatch?.[1]) {
        return decodeURIComponent(utfMatch[1]);
    }

    const match = header.match(/filename="?([^";]+)"?/i);

    return match?.[1] ?? null;
}

function triggerBrowserDownload(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.style.display = 'none';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
}

function defaultYearMonth(): { year: number; month: number } {
    const now = new Date();
    const previous = new Date(now.getFullYear(), now.getMonth() - 1, 1);

    return {
        year: previous.getFullYear(),
        month: previous.getMonth() + 1,
    };
}

function formatAmount(value: number): string {
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}

function csrfToken(): string | undefined {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content;
}

export function SalarySheetPayslipDialog({
    open,
    onOpenChange,
}: SalarySheetPayslipDialogProps): ReactElement {
    const defaults = defaultYearMonth();
    const [file, setFile] = useState<File | null>(null);
    const [year, setYear] = useState(String(defaults.year));
    const [month, setMonth] = useState(String(defaults.month));
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [selectedRows, setSelectedRows] = useState<Set<number>>(new Set());
    const [isPreviewing, setIsPreviewing] = useState(false);
    const [isGenerating, setIsGenerating] = useState(false);
    const [dragActive, setDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const allSelected = useMemo(() => {
        if (!preview || preview.rows.length === 0) {
            return false;
        }

        return preview.rows.every((row) => selectedRows.has(row.row));
    }, [preview, selectedRows]);

    const selectedCount = selectedRows.size;

    const resetState = (): void => {
        setFile(null);
        setYear(String(defaults.year));
        setMonth(String(defaults.month));
        setPreview(null);
        setSelectedRows(new Set());
        setIsPreviewing(false);
        setIsGenerating(false);
        setDragActive(false);

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const handleOpenChange = (next: boolean): void => {
        if (!next) {
            resetState();
        }

        onOpenChange(next);
    };

    const runPreview = async (selected: File): Promise<void> => {
        setIsPreviewing(true);
        setPreview(null);
        setSelectedRows(new Set());

        try {
            const body = new FormData();
            body.append('file', selected);

            const response = await fetch(previewSalarySheet.url(), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken()! } : {}),
                },
                credentials: 'same-origin',
                body,
            });

            const payload = (await response.json().catch(() => null)) as
                | (PreviewResponse & {
                      message?: string;
                      errors?: Record<string, string[]>;
                  })
                | null;

            if (!response.ok) {
                const firstError = Object.values(payload?.errors ?? {})[0]?.[0];

                throw new Error(
                    firstError ??
                        payload?.message ??
                        'Could not preview the salary sheet.',
                );
            }

            if (!payload?.rows?.length) {
                throw new Error(
                    'No payslip rows with a positive total salary were found.',
                );
            }

            setFile(selected);
            setPreview(payload);
            setSelectedRows(new Set(payload.rows.map((row) => row.row)));
        } catch (error) {
            setFile(null);
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Could not preview the salary sheet.',
            );
        } finally {
            setIsPreviewing(false);
        }
    };

    const assignFile = (next: File | null): void => {
        if (!next) {
            setFile(null);
            setPreview(null);
            setSelectedRows(new Set());

            return;
        }

        if (!isSpreadsheetLike(next)) {
            toast.error('Please upload an Excel file (.xlsx or .xls).');

            return;
        }

        void runPreview(next);
    };

    const onDrop = (event: DragEvent<HTMLDivElement>): void => {
        event.preventDefault();
        setDragActive(false);
        assignFile(event.dataTransfer.files?.[0] ?? null);
    };

    const toggleAll = (checked: boolean): void => {
        if (!preview) {
            return;
        }

        setSelectedRows(
            checked ? new Set(preview.rows.map((row) => row.row)) : new Set(),
        );
    };

    const toggleRow = (rowNumber: number, checked: boolean): void => {
        setSelectedRows((current) => {
            const next = new Set(current);

            if (checked) {
                next.add(rowNumber);
            } else {
                next.delete(rowNumber);
            }

            return next;
        });
    };

    const handleDownload = async (): Promise<void> => {
        if (!file || !preview) {
            toast.error('Please upload a salary sheet first.');

            return;
        }

        if (selectedCount === 0) {
            toast.error('Select at least one employee.');

            return;
        }

        const yearValue = Number(year);
        const monthValue = Number(month);

        if (
            !Number.isInteger(yearValue) ||
            yearValue < 2000 ||
            yearValue > 2100 ||
            !Number.isInteger(monthValue) ||
            monthValue < 1 ||
            monthValue > 12
        ) {
            toast.error('Please enter a valid year and month.');

            return;
        }

        setIsGenerating(true);

        try {
            const body = new FormData();
            body.append('file', file);
            body.append('year', String(yearValue));
            body.append('month', String(monthValue));

            for (const rowNumber of selectedRows) {
                body.append('row_numbers[]', String(rowNumber));
            }

            const response = await fetch(fromSalarySheet.url(), {
                method: 'POST',
                headers: {
                    Accept: 'application/pdf',
                    ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken()! } : {}),
                },
                body,
            });

            if (!response.ok) {
                const contentType = response.headers.get('Content-Type') ?? '';

                if (contentType.includes('application/json')) {
                    const payload = (await response.json().catch(() => null)) as {
                        message?: string;
                        errors?: Record<string, string[]>;
                    } | null;

                    const firstError = Object.values(payload?.errors ?? {})[0]?.[0];

                    throw new Error(
                        firstError ??
                            payload?.message ??
                            'Could not generate payslips from the salary sheet.',
                    );
                }

                throw new Error(
                    response.status === 422
                        ? 'The salary sheet could not be processed. Check the template and try again.'
                        : 'Could not generate payslips from the salary sheet.',
                );
            }

            const blob = await response.blob();
            const filename =
                parseContentDispositionFilename(
                    response.headers.get('Content-Disposition'),
                ) ??
                `payslips-${yearValue}-${String(monthValue).padStart(2, '0')}.pdf`;

            triggerBrowserDownload(blob, filename);
            toast.success('Payslip PDF downloaded.');
            handleOpenChange(false);
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Could not generate payslips from the salary sheet.',
            );
        } finally {
            setIsGenerating(false);
        }
    };

    const busy = isPreviewing || isGenerating;

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-4 sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>Generate payslips from salary sheet</DialogTitle>
                    <DialogDescription>
                        Upload the OMS Salary Sheet, review the imported rows,
                        select who to include, then download one multi-page PDF.
                        Nothing is saved to payroll.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-2">
                            <Label htmlFor="salary-sheet-year">Year</Label>
                            <Input
                                id="salary-sheet-year"
                                type="number"
                                min={2000}
                                max={2100}
                                value={year}
                                onChange={(event) => setYear(event.target.value)}
                                disabled={busy}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="salary-sheet-month">Month</Label>
                            <Input
                                id="salary-sheet-month"
                                type="number"
                                min={1}
                                max={12}
                                value={month}
                                onChange={(event) => setMonth(event.target.value)}
                                disabled={busy}
                            />
                        </div>
                    </div>

                    <div
                        className={cn(
                            'flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-4 py-6 text-center transition-colors',
                            dragActive
                                ? 'border-primary bg-primary/5'
                                : 'border-border/70 bg-muted/20',
                        )}
                        onDragEnter={(event) => {
                            event.preventDefault();
                            setDragActive(true);
                        }}
                        onDragOver={(event) => event.preventDefault()}
                        onDragLeave={(event) => {
                            event.preventDefault();
                            setDragActive(false);
                        }}
                        onDrop={onDrop}
                    >
                        <FileSpreadsheet className="h-8 w-8 text-muted-foreground" />
                        <div className="space-y-1">
                            <p className="text-sm font-medium">
                                {file ? file.name : 'Drop salary sheet here'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                .xlsx or .xls — worksheet name must be "Salary
                                Sheet"
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={busy}
                            onClick={() => fileInputRef.current?.click()}
                        >
                            {isPreviewing ? (
                                <>
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    Reading…
                                </>
                            ) : (
                                <>
                                    <Upload className="mr-2 h-4 w-4" />
                                    Choose file
                                </>
                            )}
                        </Button>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                            className="hidden"
                            onChange={(event) =>
                                assignFile(event.target.files?.[0] ?? null)
                            }
                        />
                    </div>

                    {preview ? (
                        <div className="space-y-2">
                            <div className="flex items-center justify-between gap-3">
                                <p className="text-sm font-medium">
                                    {selectedCount} of {preview.summary.total}{' '}
                                    selected (A–Z by name)
                                </p>
                            </div>
                            <div className="max-h-[40vh] overflow-auto rounded-xl border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-10">
                                                <Checkbox
                                                    checked={allSelected}
                                                    onCheckedChange={(value) =>
                                                        toggleAll(value === true)
                                                    }
                                                    aria-label="Select all"
                                                    disabled={busy}
                                                />
                                            </TableHead>
                                            <TableHead>Emp. no.</TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Designation</TableHead>
                                            <TableHead className="text-right">
                                                Standby days
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Onsite days
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Basic
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Supplim
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Site allow
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Standby pay
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Onsite pay
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Add / Ded
                                            </TableHead>
                                            <TableHead className="text-right">
                                                OT
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Total
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {preview.rows.map((row) => (
                                            <TableRow key={row.row}>
                                                <TableCell>
                                                    <Checkbox
                                                        checked={selectedRows.has(
                                                            row.row,
                                                        )}
                                                        onCheckedChange={(
                                                            value,
                                                        ) =>
                                                            toggleRow(
                                                                row.row,
                                                                value === true,
                                                            )
                                                        }
                                                        aria-label={`Select ${row.name}`}
                                                        disabled={busy}
                                                    />
                                                </TableCell>
                                                <TableCell>{row.employee_no}</TableCell>
                                                <TableCell className="font-medium whitespace-nowrap">
                                                    {row.name}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap">
                                                    {row.designation || '—'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {row.standby_days}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {row.onsite_days}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatAmount(row.basic_salary)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatAmount(row.supplim_allow)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatAmount(row.site_allow)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatAmount(row.standby_pay)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatAmount(row.onsite_pay)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatAmount(row.add_ded)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {formatAmount(row.overtime_pay)}
                                                </TableCell>
                                                <TableCell className="text-right font-medium">
                                                    {formatAmount(row.total_salary)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        onClick={() => handleOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={busy || !preview || selectedCount === 0}
                        onClick={() => void handleDownload()}
                    >
                        {isGenerating ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Generating…
                            </>
                        ) : (
                            'Download PDF'
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
