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

export function PayrollPopulateFromAssignmentsDialog({
    open,
    onOpenChange,
    onConfirm,
    processing,
    mode,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: () => void;
    processing: boolean;
    mode: 'populate' | 'refresh';
}) {
    const isRefresh = mode === 'refresh';

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {isRefresh
                            ? 'Refresh from Crew Assignments?'
                            : 'Populate from Crew Assignments?'}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {isRefresh
                            ? 'This will refresh Crew Timesheet movement periods from the current Crew Assignment actual movement data. Existing payroll-specific timesheet data may be updated according to the current refresh rules.'
                            : 'This will populate Crew Timesheet movement periods from the current Crew Assignment actual movement data. Existing payroll-specific timesheet data may be updated according to the current refresh rules.'}
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
                        {processing
                            ? isRefresh
                                ? 'Refreshing…'
                                : 'Populating…'
                            : isRefresh
                              ? 'Refresh timesheets'
                              : 'Populate timesheets'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
