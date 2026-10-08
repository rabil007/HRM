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

function resultTitle(result: LeaveBalanceSyncResult): string {
    switch (result.message_variant) {
        case 'no_active_leave_types':
            return 'No active leave types';
        case 'no_eligible_employees':
            return 'No eligible employees';
        case 'nothing_missing':
            return 'Leave balances are up to date';
        case 'anomalies_only':
            return 'Sync completed with anomalies';
        case 'partial_success':
            return 'Leave Balance Sync Completed — Some records require attention';
        case 'success':
        default:
            return 'Leave Balance Sync Completed';
    }
}

function ResultSummary({ result }: { result: LeaveBalanceSyncResult }) {
    switch (result.message_variant) {
        case 'no_active_leave_types':
            return (
                <p className="text-sm text-muted-foreground">
                    There are no active leave types configured for this company.
                    Missing balances cannot be created until at least one leave
                    type is active.
                </p>
            );
        case 'no_eligible_employees':
            return (
                <p className="text-sm text-muted-foreground">
                    No active employees in Attendance &amp; Leave departments
                    are visible for your account. No balances were created or
                    changed.
                </p>
            );
        case 'nothing_missing':
            return (
                <p className="text-sm text-muted-foreground">
                    All eligible employees already have their current-year leave
                    balances. No changes were needed.
                </p>
            );
        case 'anomalies_only':
            return (
                <div className="space-y-3 text-sm text-muted-foreground">
                    <p>
                        Sync completed. Some leave balances require attention
                        and were not created.
                    </p>
                    <p>
                        Skipped/anomalies:{' '}
                        <span className="font-medium text-foreground tabular-nums">
                            {result.skipped_or_anomalies}
                        </span>
                    </p>
                </div>
            );
        default:
            return (
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
            );
    }
}

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
                    <AlertDialogTitle>{resultTitle(result)}</AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <ResultSummary result={result} />
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
