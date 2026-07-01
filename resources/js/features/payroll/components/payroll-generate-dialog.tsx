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
import type { PayrollCategory } from '../types';

export function PayrollGenerateDialog({
    open,
    onOpenChange,
    onConfirm,
    processing,
    payrollCategory,
    hasExistingRecords = false,
    excludedCount = 0,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    processing: boolean;
    payrollCategory: PayrollCategory;
    hasExistingRecords?: boolean;
    excludedCount?: number;
}) {
    const isCrew = payrollCategory === 'crew';

    const officeDescription = hasExistingRecords
        ? 'Base salary will be refreshed from contracts and all salary input lines will be re-applied to gross and net pay.'
        : 'Payroll will use full monthly salary for all office employees on this run. Any salary input lines will be applied to gross and net pay.';

    const crewDescription = hasExistingRecords
        ? 'Timesheet amounts will be refreshed and all salary input lines will be re-applied to gross and net pay.'
        : 'Payroll will be calculated for employees with timesheets. Employees without timesheets will be skipped.';

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {hasExistingRecords
                            ? isCrew
                                ? 'Update crew payroll?'
                                : 'Update office payroll?'
                            : `Generate ${isCrew ? 'crew' : 'office'} payroll?`}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {isCrew ? crewDescription : officeDescription}{' '}
                        You can run this again while the period is in draft or processing.
                        
                        {excludedCount > 0 && !isCrew && (
                            <span className="mt-3 block rounded-xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-300">
                                <strong>Note:</strong> {excludedCount} employee{excludedCount === 1 ? ' is' : 's are'} unchecked and will be <strong>excluded</strong> from this pay run. Any existing payroll records for them will be deleted.
                            </span>
                        )}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl">Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        className="rounded-xl"
                        disabled={processing}
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
