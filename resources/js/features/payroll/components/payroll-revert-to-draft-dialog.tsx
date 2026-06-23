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
                    <AlertDialogTitle>Revert pay period to draft?</AlertDialogTitle>
                    <AlertDialogDescription>
                        This unlocks timesheet editing and removes generated payroll records for this
                        period. You will need to generate payroll again after making changes.
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
                        {processing ? 'Reverting…' : 'Revert to draft'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
