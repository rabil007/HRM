import { FileSpreadsheet, Loader2, Upload } from 'lucide-react';
import type { DragEvent, ReactElement } from 'react';
import { useRef, useState } from 'react';
import { fromSalarySheet } from '@/actions/App/Http/Controllers/Payroll/PayslipController';
import { Button } from '@/components/ui/button';
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
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';

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

export function SalarySheetPayslipDialog({
    open,
    onOpenChange,
}: SalarySheetPayslipDialogProps): ReactElement {
    const defaults = defaultYearMonth();
    const [file, setFile] = useState<File | null>(null);
    const [year, setYear] = useState(String(defaults.year));
    const [month, setMonth] = useState(String(defaults.month));
    const [isGenerating, setIsGenerating] = useState(false);
    const [dragActive, setDragActive] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const resetState = (): void => {
        setFile(null);
        setYear(String(defaults.year));
        setMonth(String(defaults.month));
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

    const assignFile = (next: File | null): void => {
        if (!next) {
            setFile(null);

            return;
        }

        if (!isSpreadsheetLike(next)) {
            toast.error('Please upload an Excel file (.xlsx or .xls).');

            return;
        }

        setFile(next);
    };

    const onDrop = (event: DragEvent<HTMLDivElement>): void => {
        event.preventDefault();
        setDragActive(false);
        assignFile(event.dataTransfer.files?.[0] ?? null);
    };

    const handleGenerate = async (): Promise<void> => {
        if (!file) {
            toast.error('Please choose a salary sheet file.');

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
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;

            const body = new FormData();
            body.append('file', file);
            body.append('year', String(yearValue));
            body.append('month', String(monthValue));

            const response = await fetch(fromSalarySheet.url(), {
                method: 'POST',
                headers: {
                    Accept: 'application/zip',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
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
                `payslips-${yearValue}-${String(monthValue).padStart(2, '0')}.zip`;

            triggerBrowserDownload(blob, filename);
            toast.success('Payslips downloaded.');
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

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Generate payslips from salary sheet</DialogTitle>
                    <DialogDescription>
                        Upload the OMS Salary Sheet Excel file. Payslip PDFs are
                        generated immediately and downloaded as a ZIP. Nothing is
                        saved to payroll.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
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
                                disabled={isGenerating}
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
                                disabled={isGenerating}
                            />
                        </div>
                    </div>

                    <div
                        className={cn(
                            'flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-4 py-8 text-center transition-colors',
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
                            disabled={isGenerating}
                            onClick={() => fileInputRef.current?.click()}
                        >
                            <Upload className="mr-2 h-4 w-4" />
                            Choose file
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
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={isGenerating}
                        onClick={() => handleOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={isGenerating || !file}
                        onClick={() => void handleGenerate()}
                    >
                        {isGenerating ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Generating…
                            </>
                        ) : (
                            'Generate & download'
                        )}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
