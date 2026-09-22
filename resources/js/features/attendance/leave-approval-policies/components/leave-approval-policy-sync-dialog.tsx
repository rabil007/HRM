import { router, useHttp } from '@inertiajs/react';
import { AlertTriangle, Loader2, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    syncPending,
    syncPreview,
} from '@/actions/App/Http/Controllers/Attendance/LeaveApprovalPolicyController';
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
import type { LeaveApprovalPolicy } from '../types';

export type LeaveApprovalPolicySyncPreview = {
    policy_id: number;
    policy_name: string;
    eligible_count: number;
    eligible_leave_request_ids: number[];
    skipped_approval_started_count: number;
    skipped_completed_or_ineligible_count: number;
};

export function LeaveApprovalPolicySyncDialog({
    open,
    onOpenChange,
    policy,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    policy: LeaveApprovalPolicy | null;
}) {
    const http = useHttp<Record<string, never>, LeaveApprovalPolicySyncPreview>(
        {},
    );
    const [preview, setPreview] =
        useState<LeaveApprovalPolicySyncPreview | null>(null);
    const [loadingPreview, setLoadingPreview] = useState(false);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [syncing, setSyncing] = useState(false);

    useEffect(() => {
        if (!open || !policy) {
            setPreview(null);
            setPreviewError(null);
            setLoadingPreview(false);
            setSyncing(false);

            return;
        }

        let cancelled = false;
        setLoadingPreview(true);
        setPreviewError(null);
        setPreview(null);

        http.post(syncPreview.url(policy.id))
            .then((res) => {
                if (!cancelled) {
                    setPreview(res);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPreview(null);
                    setPreviewError(
                        'Unable to check pending leave requests. Please try again.',
                    );
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingPreview(false);
                }
            });

        return () => {
            cancelled = true;
        };
        // useHttp() returns a new object each render; keep dependencies stable.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, policy?.id]);

    const eligibleCount = preview?.eligible_count ?? 0;
    const canConfirm =
        !loadingPreview &&
        previewError === null &&
        preview !== null &&
        eligibleCount > 0 &&
        !syncing;

    const confirm = (): void => {
        if (!policy || !canConfirm) {
            return;
        }

        setSyncing(true);
        router.post(
            syncPending.url(policy.id),
            {},
            {
                preserveScroll: true,
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
                        Apply this approval policy to pending leave requests?
                    </AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="space-y-4 text-sm text-muted-foreground">
                            <p>
                                This will rebuild the approval workflow for
                                eligible pending leave requests using the
                                current policy configuration.
                            </p>

                            <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-amber-900 dark:text-amber-100">
                                <div className="mb-2 flex items-center gap-2 text-sm font-semibold">
                                    <AlertTriangle className="h-4 w-4 shrink-0" />
                                    Caution
                                </div>
                                <ul className="list-disc space-y-1.5 pl-5 text-xs leading-relaxed">
                                    <li>
                                        Only leave requests in the active
                                        company will be considered.
                                    </li>
                                    <li>
                                        Approved, rejected, cancelled, or
                                        otherwise completed requests will not be
                                        changed.
                                    </li>
                                    <li>
                                        Requests where an approver has already
                                        made a decision will not be reset
                                        automatically.
                                    </li>
                                    <li>
                                        Existing approval history and audit
                                        records must be preserved.
                                    </li>
                                    <li>
                                        Eligible requests will use the approval
                                        policy configuration currently saved in
                                        the system.
                                    </li>
                                </ul>
                            </div>

                            {loadingPreview ? (
                                <p className="flex items-center gap-2 text-sm">
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                    Checking pending requests…
                                </p>
                            ) : null}

                            {previewError ? (
                                <p className="text-sm text-destructive">
                                    {previewError}
                                </p>
                            ) : null}

                            {preview && !loadingPreview && !previewError ? (
                                <div className="space-y-2 rounded-xl border border-border/60 bg-muted/30 px-4 py-3">
                                    <p className="text-sm font-semibold text-foreground">
                                        Eligible requests:{' '}
                                        <span className="tabular-nums">
                                            {preview.eligible_count}
                                        </span>
                                    </p>
                                    {(preview.skipped_approval_started_count >
                                        0 ||
                                        preview.skipped_completed_or_ineligible_count >
                                            0) && (
                                        <div className="space-y-1 text-xs">
                                            <p className="font-medium text-foreground/80">
                                                Skipped:
                                            </p>
                                            {preview.skipped_approval_started_count >
                                            0 ? (
                                                <p>
                                                    Approval already started:{' '}
                                                    <span className="tabular-nums">
                                                        {
                                                            preview.skipped_approval_started_count
                                                        }
                                                    </span>
                                                </p>
                                            ) : null}
                                            {preview.skipped_completed_or_ineligible_count >
                                            0 ? (
                                                <p>
                                                    Completed / cancelled /
                                                    otherwise ineligible:{' '}
                                                    <span className="tabular-nums">
                                                        {
                                                            preview.skipped_completed_or_ineligible_count
                                                        }
                                                    </span>
                                                </p>
                                            ) : null}
                                        </div>
                                    )}
                                    {eligibleCount === 0 ? (
                                        <p className="pt-1 text-sm font-medium text-foreground">
                                            No eligible pending requests
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={syncing}>
                        Cancel
                    </AlertDialogCancel>
                    {eligibleCount > 0 || loadingPreview ? (
                        <Button
                            type="button"
                            onClick={confirm}
                            disabled={!canConfirm}
                        >
                            {syncing ? (
                                <>
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    Syncing…
                                </>
                            ) : (
                                <>
                                    <RefreshCw className="mr-2 h-4 w-4" />
                                    Sync {eligibleCount} Request
                                    {eligibleCount === 1 ? '' : 's'}
                                </>
                            )}
                        </Button>
                    ) : null}
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
