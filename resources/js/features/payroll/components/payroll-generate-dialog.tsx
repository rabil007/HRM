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
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    processing: boolean;
    payrollCategory: PayrollCategory;
}) {
    const isCrew = payrollCategory === 'crew';

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Generate {isCrew ? 'crew' : 'office'} payroll?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {isCrew
                            ? 'Payroll will be calculated for employees with timesheets. Employees without timesheets will be skipped.'
                            : 'Payroll will be calculated from attendance records in this period. Employees without attendance will be skipped.'}{' '}
                        You can re-generate while the period is in draft or processing.
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
                        {processing ? 'Generating…' : 'Generate payroll'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
