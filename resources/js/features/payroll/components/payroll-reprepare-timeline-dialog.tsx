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

export function PayrollReprepareTimelineDialog({
    open,
    onOpenChange,
    onConfirm,
    processing,
    currentVersion,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    processing: boolean;
    currentVersion: number;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Prepare a new Crew Timesheet version?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        This creates a new draft Crew Timesheet version from the
                        latest Crew Operations movement data. The current version{' '}
                        {currentVersion} and any review progress on it will be
                        superseded. Use this when the underlying Crew Operations
                        data has changed.
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
                        {processing ? 'Preparing…' : 'Create new version'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
