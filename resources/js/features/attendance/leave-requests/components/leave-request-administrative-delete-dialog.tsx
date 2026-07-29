import { useForm } from '@inertiajs/react';
import { administrativeDestroy } from '@/actions/App/Http/Controllers/Attendance/LeaveRequestController';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDisplayDate } from '@/lib/format-date';
import type { LeaveRequest } from '../types';

export function LeaveRequestAdministrativeDeleteDialog({
    open,
    onOpenChange,
    leaveRequest,
    onSuccess,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    leaveRequest: LeaveRequest | null;
    onSuccess: () => void;
}) {
    const form = useForm<{
        administrative_deletion_reason: string;
    }>({
        administrative_deletion_reason: '',
    });

    const isApproved = leaveRequest?.status === 'approved';
    const bagErrors = form.errors as Record<string, string | undefined>;

    const submit = () => {
        if (!leaveRequest || !form.data.administrative_deletion_reason.trim()) {
            return;
        }

        form.delete(administrativeDestroy.url(leaveRequest.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.clearErrors();
                onOpenChange(false);
                onSuccess();
            },
        });
    };

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.reset();
                    form.clearErrors();
                }

                onOpenChange(next);
            }}
        >
            <AlertDialogContent className="max-w-lg glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Void and remove leave request
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        This privileged action soft-deletes the request,
                        reverses its leave balance effect, and preserves
                        approval history. It is not an ordinary delete.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                {leaveRequest ? (
                    <div className="space-y-3 rounded-xl border border-border/60 bg-muted/30 p-3 text-sm dark:border-white/6 dark:bg-white/4">
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">
                                Employee
                            </span>
                            <span className="text-right font-semibold">
                                {leaveRequest.employee?.name ?? '—'}
                            </span>
                        </div>
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">
                                Leave type
                            </span>
                            <span className="text-right font-semibold">
                                {leaveRequest.leave_type?.name ?? '—'}
                            </span>
                        </div>
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">Dates</span>
                            <span className="text-right font-semibold">
                                {formatDisplayDate(leaveRequest.start_date)} —{' '}
                                {formatDisplayDate(leaveRequest.end_date)}
                            </span>
                        </div>
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">
                                Status
                            </span>
                            <span className="font-semibold tracking-wide uppercase">
                                {leaveRequest.status}
                            </span>
                        </div>
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">Days</span>
                            <span className="font-semibold tabular-nums">
                                {leaveRequest.total_days}
                            </span>
                        </div>
                    </div>
                ) : null}

                <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-900 dark:text-amber-100">
                    Leave balance for this request will be reversed where
                    applicable.
                </div>

                {isApproved ? (
                    <div className="rounded-xl border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                        This request is approved. Voiding it will subtract the
                        allocated days from used leave and restore remaining
                        balance.
                    </div>
                ) : null}

                <div className="space-y-2">
                    <Label
                        htmlFor="administrative_deletion_reason"
                        className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                    >
                        Deletion reason
                    </Label>
                    <Textarea
                        id="administrative_deletion_reason"
                        value={form.data.administrative_deletion_reason}
                        onChange={(e) =>
                            form.setData(
                                'administrative_deletion_reason',
                                e.target.value,
                            )
                        }
                        className="min-h-24 rounded-xl border-border bg-card"
                        placeholder="Explain why this leave request is being voided..."
                    />
                    {form.errors.administrative_deletion_reason ? (
                        <div className="text-xs font-medium text-destructive">
                            {form.errors.administrative_deletion_reason}
                        </div>
                    ) : null}
                    {bagErrors.leave_request ? (
                        <div className="text-xs font-medium text-destructive">
                            {bagErrors.leave_request}
                        </div>
                    ) : null}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl glass-card hover:bg-accent">
                        Keep request
                    </AlertDialogCancel>
                    <Button
                        variant="destructive"
                        className="rounded-xl"
                        onClick={submit}
                        disabled={
                            form.processing ||
                            !form.data.administrative_deletion_reason.trim()
                        }
                    >
                        Void and remove
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
