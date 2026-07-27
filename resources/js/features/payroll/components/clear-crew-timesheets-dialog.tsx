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

type ClearCrewTimesheetsDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    clearableCount: number;
    isClearing: boolean;
    onConfirm: () => void;
};

export function ClearCrewTimesheetsDialog({
    open,
    onOpenChange,
    clearableCount,
    isClearing,
    onConfirm,
}: ClearCrewTimesheetsDialogProps) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Clear all Manual and Imported timesheets?
                    </AlertDialogTitle>
                    <AlertDialogDescription className="space-y-2">
                        <span className="block">
                            This will remove all manually entered and
                            Excel-imported timesheet data from this draft pay
                            period. Crew Operations timesheets and timeline data
                            will not be affected.
                        </span>
                        {clearableCount > 0 ? (
                            <span className="block font-medium text-foreground">
                                {clearableCount}{' '}
                                {clearableCount === 1
                                    ? 'timesheet'
                                    : 'timesheets'}{' '}
                                will be cleared.
                            </span>
                        ) : null}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel
                        className="rounded-xl"
                        disabled={isClearing}
                    >
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className="rounded-xl bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        disabled={isClearing || clearableCount === 0}
                        onClick={(event) => {
                            event.preventDefault();
                            onConfirm();
                        }}
                    >
                        {isClearing ? 'Clearing…' : 'Clear Timesheets'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
