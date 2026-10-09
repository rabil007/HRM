import { useHttp } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { groupCrewPayrollBlockingIssues } from '../lib/group-crew-payroll-blocking-issues';
import type { CrewPayrollBlockingIssueGroup } from '../lib/group-crew-payroll-blocking-issues';
import { payrollGenerateReviewCanConfirm } from '../lib/payroll-generate-review';
import type { CrewPayrollGenerationPreview, PayrollCategory } from '../types';

function PreviewIssueList({
    groups,
}: {
    groups: CrewPayrollBlockingIssueGroup[];
}) {
    return (
        <ul className="max-h-48 list-none space-y-2 overflow-y-auto">
            {groups.map((group) => (
                <li
                    key={group.key}
                    className="rounded-lg border border-current/10 bg-background/40 px-2.5 py-2"
                >
                    {group.employeeName ? (
                        <p className="font-semibold text-foreground">
                            {group.employeeName}
                        </p>
                    ) : null}
                    <p className="mt-0.5 text-[11px] leading-relaxed">
                        {group.message}
                    </p>
                    {group.action ? (
                        <p className="mt-1 text-[11px] font-medium opacity-90">
                            Action: {group.action}
                        </p>
                    ) : null}
                </li>
            ))}
        </ul>
    );
}

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
    onConfirm: (options?: {
        acknowledge_future_payable_days?: boolean;
    }) => void;
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
    const [acknowledgeFutureDays, setAcknowledgeFutureDays] = useState(false);

    const blockingGroups = useMemo(
        () => groupCrewPayrollBlockingIssues(preview?.blocking_issues ?? []),
        [preview],
    );
    const warningGroups = useMemo(
        () => groupCrewPayrollBlockingIssues(preview?.warning_issues ?? []),
        [preview],
    );
    const skippedGroups = useMemo(
        () => groupCrewPayrollBlockingIssues(preview?.skipped_issues ?? []),
        [preview],
    );
    const automaticGroups = useMemo(
        () =>
            groupCrewPayrollBlockingIssues(
                preview?.automatic_adjustments ?? [],
            ),
        [preview],
    );

    useEffect(() => {
        if (!open || !isCrew) {
            return;
        }

        let cancelled = false;
        setLoadingPreview(true);
        setPreviewError(null);
        setPreview(null);
        setAcknowledgeFutureDays(false);
        http.setData({ excluded_employee_ids: excludedEmployeeIds });

        http.post(CrewPayrollGenerationPreviewController.url(periodId))
            .then((res) => {
                if (!cancelled) {
                    setPreview(res);
                    setAcknowledgeFutureDays(false);
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

    const requiresFutureDaysAcknowledgment = Boolean(
        preview?.requires_future_days_acknowledgment,
    );
    const canConfirmCrew =
        payrollGenerateReviewCanConfirm(preview) &&
        (!requiresFutureDaysAcknowledgment || acknowledgeFutureDays);
    const skippedCount =
        (preview?.skipped_count ?? 0) ||
        (preview?.missing_timesheet_count ?? 0) +
            (preview?.excluded_count ?? 0);
    const futureDaysCount = preview?.future_payable_days_count ?? 0;
    const futureEmployeeCount = preview?.future_payable_employee_count ?? 0;

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="max-h-[90vh] max-w-xl overflow-y-auto glass-card">
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

                                        {preview.blocking_count > 0 ? (
                                            <div className="space-y-2 rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive">
                                                <p className="font-semibold">
                                                    Blocking errors (
                                                    {blockingGroups.length})
                                                </p>
                                                <p className="text-[11px] opacity-90">
                                                    Must be fixed before payroll
                                                    can be generated.
                                                </p>
                                                <PreviewIssueList
                                                    groups={blockingGroups}
                                                />
                                            </div>
                                        ) : null}

                                        {requiresFutureDaysAcknowledgment ? (
                                            <div className="space-y-3 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-950 dark:text-amber-100">
                                                <div className="space-y-1">
                                                    <p className="text-sm font-semibold text-amber-950 dark:text-amber-50">
                                                        Future payable dates
                                                        detected
                                                    </p>
                                                    <p className="text-[11px] leading-relaxed text-amber-900/90 dark:text-amber-100/90">
                                                        This payroll includes{' '}
                                                        <strong>
                                                            {futureDaysCount}{' '}
                                                            payable{' '}
                                                            {futureDaysCount ===
                                                            1
                                                                ? 'day'
                                                                : 'days'}
                                                        </strong>{' '}
                                                        after today&apos;s date
                                                        across{' '}
                                                        <strong>
                                                            {
                                                                futureEmployeeCount
                                                            }{' '}
                                                            {futureEmployeeCount ===
                                                            1
                                                                ? 'employee'
                                                                : 'employees'}
                                                        </strong>
                                                        .
                                                        {preview.future_payable_from &&
                                                        preview.future_payable_to
                                                            ? ` Affected dates: ${preview.future_payable_from} – ${preview.future_payable_to}.`
                                                            : null}
                                                    </p>
                                                    <p className="text-[11px] leading-relaxed text-amber-900/90 dark:text-amber-100/90">
                                                        These dates were
                                                        synchronized from
                                                        recorded timesheets.
                                                        Generating payroll will
                                                        include them in salary
                                                        calculations even though
                                                        the calendar dates have
                                                        not yet occurred.
                                                    </p>
                                                </div>
                                                <div className="flex items-start gap-2 rounded-lg border border-amber-500/20 bg-background/50 p-2.5">
                                                    <Checkbox
                                                        id="acknowledge-future-payable-days"
                                                        checked={
                                                            acknowledgeFutureDays
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            setAcknowledgeFutureDays(
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                        className="mt-0.5"
                                                    />
                                                    <Label
                                                        htmlFor="acknowledge-future-payable-days"
                                                        className="cursor-pointer text-[11px] leading-relaxed font-normal text-amber-950 dark:text-amber-50"
                                                    >
                                                        I understand that this
                                                        payroll includes future
                                                        payable dates and
                                                        confirm that I want to
                                                        proceed.
                                                    </Label>
                                                </div>
                                            </div>
                                        ) : null}

                                        {skippedCount > 0 ||
                                        warningGroups.length > 0 ? (
                                            <div className="space-y-2 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-200">
                                                <p className="font-semibold">
                                                    Warnings / skipped
                                                </p>
                                                <p className="text-[11px] text-amber-700/90 dark:text-amber-200/80">
                                                    Generation may continue for
                                                    ready employees. Skipped
                                                    employees are not paid in
                                                    this run.
                                                </p>
                                                {skippedGroups.length > 0 ? (
                                                    <PreviewIssueList
                                                        groups={skippedGroups}
                                                    />
                                                ) : (
                                                    <>
                                                        {preview.missing_timesheet_count >
                                                        0 ? (
                                                            <p>
                                                                {
                                                                    preview.missing_timesheet_count
                                                                }{' '}
                                                                employees have
                                                                no timesheet
                                                            </p>
                                                        ) : null}
                                                        {preview.excluded_count >
                                                        0 ? (
                                                            <p>
                                                                {
                                                                    preview.excluded_count
                                                                }{' '}
                                                                employees are
                                                                explicitly
                                                                excluded
                                                            </p>
                                                        ) : null}
                                                    </>
                                                )}
                                                {warningGroups.length > 0 ? (
                                                    <>
                                                        <p className="pt-1 font-semibold">
                                                            Warnings (
                                                            {
                                                                warningGroups.length
                                                            }
                                                            )
                                                        </p>
                                                        <PreviewIssueList
                                                            groups={
                                                                warningGroups
                                                            }
                                                        />
                                                    </>
                                                ) : null}
                                            </div>
                                        ) : null}

                                        {automaticGroups.length > 0 ? (
                                            <div className="space-y-2 rounded-xl border border-sky-500/30 bg-sky-500/10 p-3 text-xs text-sky-950 dark:text-sky-100">
                                                <p className="font-semibold">
                                                    Automatic adjustments (
                                                    {automaticGroups.length})
                                                </p>
                                                <p className="text-[11px] opacity-90">
                                                    The system handled these
                                                    decisions automatically.
                                                    They do not block
                                                    generation.
                                                </p>
                                                <PreviewIssueList
                                                    groups={automaticGroups}
                                                />
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
                                            Only employees with a usable Crew
                                            Timesheet are included. Missing
                                            timesheets are skipped. Blocking
                                            validation issues must be corrected
                                            first.
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
                            onConfirm(
                                requiresFutureDaysAcknowledgment
                                    ? {
                                          acknowledge_future_payable_days: true,
                                      }
                                    : undefined,
                            );
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
