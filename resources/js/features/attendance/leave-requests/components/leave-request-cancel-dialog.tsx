import { useForm } from '@inertiajs/react';
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
import type { LeaveRequest } from '../types';

export function LeaveRequestCancelDialog({
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
    const form = useForm({
        cancellation_reason: '',
    });

    const submit = () => {
        if (!leaveRequest || !form.data.cancellation_reason.trim()) {
            return;
        }

        form.put(`/attendance/leave-requests/${leaveRequest.id}/cancel`, {
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
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>Cancel leave request</AlertDialogTitle>
                    <AlertDialogDescription>
                        {leaveRequest?.employee?.name
                            ? `Provide a reason for cancelling ${leaveRequest.employee.name}'s leave request.`
                            : 'Provide a reason for cancelling this leave request.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div className="space-y-2">
                    <Label htmlFor="cancellation_reason" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Cancellation reason
                    </Label>
                    <Textarea
                        id="cancellation_reason"
                        value={form.data.cancellation_reason}
                        onChange={(e) => form.setData('cancellation_reason', e.target.value)}
                        className="min-h-24 rounded-xl border-border bg-card"
                        placeholder="Reason for cancellation..."
                    />
                    {form.errors.cancellation_reason ? (
                        <div className="text-xs font-medium text-destructive">{form.errors.cancellation_reason}</div>
                    ) : null}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel className="glass-card rounded-xl hover:bg-accent">Cancel</AlertDialogCancel>
                    <Button
                        className="rounded-xl"
                        onClick={submit}
                        disabled={form.processing || !form.data.cancellation_reason.trim()}
                    >
                        Cancel request
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
