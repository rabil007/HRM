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

export function PayrollRevertToProcessingDialog({
    open,
    onOpenChange,
    onConfirm,
    processing,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    processing: boolean;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Revert pay period to processing?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        This removes generated payslips and clears WPS data for
                        this pay run. Payroll records are kept but unlocked so
                        you can approve the period again.
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
                        {processing ? 'Reverting…' : 'Revert to processing'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
