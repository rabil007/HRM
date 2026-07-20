import { useEffect, useState } from 'react';
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
import { Switch } from '@/components/ui/switch';

export function PayrollRevertToDraftDialog({
    open,
    onOpenChange,
    onConfirm,
    processing,
    supportsTimesheets,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: (options: { clearTimesheets: boolean }) => void;
    processing: boolean;
    supportsTimesheets: boolean;
}) {
    const [clearTimesheets, setClearTimesheets] = useState(false);

    useEffect(() => {
        if (!open) {
            setClearTimesheets(false);
        }
    }, [open]);

    const description = supportsTimesheets
        ? clearTimesheets
            ? 'This removes all payroll records, salary inputs, and timesheets for this period. Excluded employees are reset, so everyone will be included the next time you generate payroll unless you exclude them again. You will need to enter timesheets and generate payroll again.'
            : 'This removes all payroll records and salary inputs for this period. Timesheets are kept. Excluded employees are reset, so everyone will be included the next time you generate payroll unless you exclude them again.'
        : 'This removes generated payroll records for this period and resets employee selection. All employees will be included the next time you generate payroll unless you exclude them again on the Employees tab.';

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Revert pay period to draft?
                    </AlertDialogTitle>
                    {supportsTimesheets ? (
                        <div className="flex items-center justify-between rounded-xl border border-border/60 px-4 py-3">
                            <div>
                                <p className="text-sm font-medium text-foreground">
                                    Clear timesheets
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Also delete crew timesheets for this period
                                </p>
                            </div>
                            <Switch
                                checked={clearTimesheets}
                                onCheckedChange={setClearTimesheets}
                            />
                        </div>
                    ) : null}
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
                            onConfirm({ clearTimesheets });
                        }}
                    >
                        {processing ? 'Reverting…' : 'Revert to draft'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
