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

export function PayrollRevertToDraftDialog({
    open,
    onOpenChange,
    onConfirm,
    processing,
    supportsTimesheets,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    processing: boolean;
    supportsTimesheets: boolean;
}) {
    const description = supportsTimesheets
        ? 'This unlocks timesheet editing and removes generated payroll records for this period. Excluded employees are reset, so everyone will be included the next time you generate payroll unless you exclude them again. You will need to generate payroll again after making changes.'
        : 'This removes generated payroll records for this period and resets employee selection. All employees will be included the next time you generate payroll unless you exclude them again on the Employees tab.';

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Revert pay period to draft?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl">
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className="rounded-xl"
                        disabled={processing}
                        onClick={(event) => {
                            event.preventDefault();
                            onConfirm();
                        }}
                    >
                        {processing ? 'Reverting…' : 'Revert to draft'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
