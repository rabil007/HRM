import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import type { LeaveBalanceSyncResult } from './types';

export function SyncMissingBalancesResultDialog({
    result,
    open,
    onOpenChange,
}: {
    result: LeaveBalanceSyncResult | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    if (!result) {
        return null;
    }

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="max-w-lg glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {result.nothing_to_create
                            ? 'Leave balances are up to date'
                            : 'Leave Balance Sync Completed'}
                    </AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        {result.nothing_to_create ? (
                            <p className="text-sm text-muted-foreground">
                                All eligible employees already have their
                                current-year leave balances. No changes were
                                needed.
                            </p>
                        ) : (
                            <ul className="list-disc space-y-1.5 pl-5 text-sm text-muted-foreground">
                                <li>
                                    Eligible employees checked:{' '}
                                    <span className="font-medium text-foreground tabular-nums">
                                        {result.eligible_employees_checked}
                                    </span>
                                </li>
                                <li>
                                    Employees with newly created balances:{' '}
                                    <span className="font-medium text-foreground tabular-nums">
                                        {result.employees_with_new_balances}
                                    </span>
                                </li>
                                <li>
                                    New balance records created:{' '}
                                    <span className="font-medium text-foreground tabular-nums">
                                        {result.new_balance_records_created}
                                    </span>
                                </li>
                                <li>
                                    Already existing balances:{' '}
                                    <span className="font-medium text-foreground tabular-nums">
                                        {result.already_existing_balances}
                                    </span>
                                </li>
                                <li>
                                    Skipped/anomalies:{' '}
                                    <span className="font-medium text-foreground tabular-nums">
                                        {result.skipped_or_anomalies}
                                    </span>
                                </li>
                            </ul>
                        )}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogAction onClick={() => onOpenChange(false)}>
                        Close
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
