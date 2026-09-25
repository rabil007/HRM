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
                        Refresh Crew Timesheets from assignments?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        This repopulates timesheets from the latest Crew
                        Assignment movement data. The current version{' '}
                        {currentVersion} will be superseded. Use this when the
                        underlying Crew Assignment data has changed.
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
                        {processing ? 'Refreshing…' : 'Refresh timesheets'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
