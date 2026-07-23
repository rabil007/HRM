import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import CrewPayrollGenerationPreviewController from '@/actions/App/Http/Controllers/Payroll/CrewPayrollGenerationPreviewController';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import type { CrewPayrollGenerationPreview, PayrollCategory } from '../types';

export function PayrollGenerateDialog({
    open,
    onOpenChange,
    onConfirm,
    processing,
    payrollCategory,
    periodId,
    hasExistingRecords = false,
    excludedCount = 0,
    excludedEmployeeIds = [],
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    processing: boolean;
    payrollCategory: PayrollCategory;
    periodId: number;
    hasExistingRecords?: boolean;
    excludedCount?: number;
    excludedEmployeeIds?: number[];
}) {
    const isCrew = payrollCategory === 'crew';
    const http = useHttp<
        { excluded_employee_ids: number[] },
        CrewPayrollGenerationPreview
    >({
        excluded_employee_ids: [],
    });
    const [preview, setPreview] = useState<CrewPayrollGenerationPreview | null>(
        null,
    );
    const [loadingPreview, setLoadingPreview] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);

    useEffect(() => {
        if (!open || !isCrew) {
            return;
        }

        let cancelled = false;
        setLoadingPreview(true);
        setPreviewError(null);
        setPreview(null);
        http.setData({ excluded_employee_ids: excludedEmployeeIds });

        http.post(CrewPayrollGenerationPreviewController.url(periodId))
            .then((res) => {
                if (!cancelled) {
                    setPreview(res);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPreviewError('Unable to load generation preview.');
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingPreview(false);
                }
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, isCrew, periodId, excludedEmployeeIds.join(',')]);

    const officeDescription = hasExistingRecords
        ? 'Base salary will be refreshed from contracts and all salary input lines will be re-applied to gross and net pay.'
        : 'Payroll will use full monthly salary for all office employees on this run. Any salary input lines will be applied to gross and net pay.';

    const canConfirmCrew =
        preview !== null &&
        preview.blocking_count === 0 &&
        preview.ready_count > 0;

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="max-w-lg glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {isCrew
                            ? 'Payroll generation review'
                            : hasExistingRecords
                              ? 'Update office payroll?'
                              : 'Generate office payroll?'}
                    </AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="space-y-3 text-sm text-muted-foreground">
                            {isCrew ? (
                                loadingPreview ? (
                                    <p>Calculating generation preview…</p>
                                ) : previewError ? (
                                    <p className="text-destructive">
                                        {previewError}
                                    </p>
                                ) : preview ? (
                                    <>
                                        <p>
                                            Ready:{' '}
                                            <strong className="text-foreground">
                                                {preview.ready_count} employees
                                            </strong>
                                        </p>
                                        {(preview.missing_timesheet_count > 0 ||
                                            preview.awaiting_approval_count >
                                                0 ||
                                            preview.excluded_count > 0) && (
                                            <div className="space-y-1 rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-200">
                                                <p className="font-semibold">
                                                    Will be skipped
                                                </p>
                                                {preview.missing_timesheet_count >
                                                0 ? (
                                                    <p>
                                                        {
                                                            preview.missing_timesheet_count
                                                        }{' '}
                                                        employees have no
                                                        timesheet
                                                    </p>
                                                ) : null}
                                                {preview.awaiting_approval_count >
                                                0 ? (
                                                    <p>
                                                        {
                                                            preview.awaiting_approval_count
                                                        }{' '}
                                                        employees are awaiting
                                                        approval
                                                    </p>
                                                ) : null}
                                                {preview.excluded_count > 0 ? (
                                                    <p>
                                                        {preview.excluded_count}{' '}
                                                        employees are explicitly
                                                        excluded
                                                    </p>
                                                ) : null}
                                            </div>
                                        )}
                                        {preview.blocking_count > 0 ? (
                                            <div className="space-y-2 rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive">
                                                <p className="font-semibold">
                                                    Blocking (
                                                    {preview.blocking_count})
                                                </p>
                                                <ul className="list-disc space-y-1 pl-4">
                                                    {preview.blocking_issues
                                                        .slice(0, 5)
                                                        .map((issue, index) => (
                                                            <li key={index}>
                                                                {issue.message}
                                                            </li>
                                                        ))}
                                                </ul>
                                            </div>
                                        ) : null}
                                        {preview.ready_count === 0 &&
                                        preview.blocking_count === 0 ? (
                                            <p>
                                                No employees are ready for
                                                payroll.
                                            </p>
                                        ) : null}
                                        <p>
                                            Only Ready employees receive payroll
                                            records. Missing or unapproved
                                            timesheets are skipped.
                                        </p>
                                    </>
                                ) : null
                            ) : (
                                <>
                                    <p>{officeDescription}</p>
                                    <p>
                                        You can run this again while the period
                                        is in draft or processing.
                                    </p>
                                    {excludedCount > 0 ? (
                                        <span className="mt-1 block rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
                                            <strong>Note:</strong>{' '}
                                            {excludedCount} employee
                                            {excludedCount === 1
                                                ? ' is'
                                                : 's are'}{' '}
                                            unchecked and will be{' '}
                                            <strong>excluded</strong> from this
                                            pay run. Any existing payroll
                                            records for them will be deleted.
                                        </span>
                                    ) : null}
                                </>
                            )}
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl">
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className="rounded-xl"
                        disabled={
                            processing ||
                            (isCrew &&
                                (loadingPreview ||
                                    !!previewError ||
                                    !canConfirmCrew))
                        }
                        onClick={(event) => {
                            event.preventDefault();
                            onConfirm();
                        }}
                    >
                        {processing
                            ? hasExistingRecords
                                ? 'Updating…'
                                : 'Generating…'
                            : hasExistingRecords
                              ? 'Update payroll'
                              : 'Generate payroll'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
