import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import type { LeaveRequest } from '../types';

export function LeaveRequestRejectDialog({
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
        rejection_reason: '',
    });

    const submit = () => {
        if (!leaveRequest) {
            return;
        }

        form.put(`/attendance/leave-requests/${leaveRequest.id}/reject`, {
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
                    <AlertDialogTitle>Reject leave request</AlertDialogTitle>
                    <AlertDialogDescription>
                        {leaveRequest?.employee?.name
                            ? `Provide a reason for rejecting ${leaveRequest.employee.name}'s leave request.`
                            : 'Provide a reason for rejecting this leave request.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div className="space-y-2">
                    <Label htmlFor="rejection_reason" className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Rejection reason
                    </Label>
                    <Textarea
                        id="rejection_reason"
                        value={form.data.rejection_reason}
                        onChange={(e) => form.setData('rejection_reason', e.target.value)}
                        className="min-h-24 rounded-xl border-border bg-card"
                        placeholder="Reason for rejection..."
                    />
                    {form.errors.rejection_reason ? (
                        <div className="text-xs font-medium text-destructive">{form.errors.rejection_reason}</div>
                    ) : null}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel className="glass-card rounded-xl hover:bg-accent">Cancel</AlertDialogCancel>
                    <Button className="rounded-xl" onClick={submit} disabled={form.processing}>
                        Reject request
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
