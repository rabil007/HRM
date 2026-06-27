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
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    processing: boolean;
    payrollCategory: PayrollCategory;
    hasExistingRecords?: boolean;
}) {
    const isCrew = payrollCategory === 'crew';

    const officeDescription = hasExistingRecords
        ? 'Base salary will be refreshed from contracts and all salary input lines will be re-applied to gross and net pay.'
        : 'Payroll will use full monthly salary for all office employees on this run. Any salary input lines will be applied to gross and net pay.';

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {hasExistingRecords && !isCrew
                            ? 'Update office payroll?'
                            : `Generate ${isCrew ? 'crew' : 'office'} payroll?`}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {isCrew
                            ? 'Payroll will be calculated for employees with timesheets. Employees without timesheets will be skipped.'
                            : officeDescription}{' '}
                        You can run this again while the period is in draft or processing.
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
                            ? hasExistingRecords && !isCrew
                                ? 'Updating…'
                                : 'Generating…'
                            : hasExistingRecords && !isCrew
                              ? 'Update payroll'
                              : 'Generate payroll'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
