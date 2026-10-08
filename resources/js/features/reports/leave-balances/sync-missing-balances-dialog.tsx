import { router } from '@inertiajs/react';
import { Loader2, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { syncMissing } from '@/routes/organization/reports/leave-balances';
import type { LeaveBalanceSyncResult } from './types';

type SyncMissingBalancesDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onCompleted: (result: LeaveBalanceSyncResult) => void;
};

export function SyncMissingBalancesDialog({
    open,
    onOpenChange,
    onCompleted,
}: SyncMissingBalancesDialogProps) {
    const [syncing, setSyncing] = useState(false);

    const confirm = (): void => {
        if (syncing) {
            return;
        }

        setSyncing(true);
        router.post(
            syncMissing.url(),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const result = (
                        page.props as {
                            flash?: {
                                leave_balance_sync_result?: LeaveBalanceSyncResult;
                            };
                        }
                    ).flash?.leave_balance_sync_result;

                    if (result) {
                        onCompleted(result);
                        router.reload({
                            only: [
                                'balances',
                                'pagination',
                                'summary',
                                'filter_options',
                                'department_tree',
                            ],
                        });
                    }
                },
                onFinish: () => {
                    setSyncing(false);
                    onOpenChange(false);
                },
            },
        );
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="max-w-lg glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Sync Missing Leave Balances
                    </AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="space-y-3 text-sm text-muted-foreground">
                            <p>
                                This action will check eligible employees for
                                missing leave balances in the current business
                                year.
                            </p>
                            <p>
                                Only missing balances will be created using the
                                configured leave types.
                            </p>
                            <p>
                                Existing balances, previously used days, pending
                                days, carry-forward amounts, and historical
                                allocations will remain unchanged.
                            </p>
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={syncing}>
                        Cancel
                    </AlertDialogCancel>
                    <Button type="button" onClick={confirm} disabled={syncing}>
                        {syncing ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Syncing…
                            </>
                        ) : (
                            <>
                                <RefreshCw className="mr-2 h-4 w-4" />
                                Confirm Sync
                            </>
                        )}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
