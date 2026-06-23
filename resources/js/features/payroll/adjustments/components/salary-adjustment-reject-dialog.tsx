import { useForm } from '@inertiajs/react';
import { reject as rejectAdjustment } from '@/actions/App/Http/Controllers/Payroll/SalaryAdjustmentController';
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
import type { SalaryAdjustment } from '../types';

export function SalaryAdjustmentRejectDialog({
    open,
    onOpenChange,
    adjustment,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    adjustment: SalaryAdjustment | null;
}) {
    const form = useForm({ rejection_reason: '' });

    const submit = () => {
        if (!adjustment || !form.data.rejection_reason.trim()) {
            return;
        }

        form.put(rejectAdjustment.url(adjustment.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.clearErrors();
                onOpenChange(false);
            },
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>Reject salary adjustment</AlertDialogTitle>
                    <AlertDialogDescription>
                        {adjustment?.employee?.name
                            ? `Provide a reason for rejecting the adjustment for ${adjustment.employee.name}.`
                            : 'Provide a reason for rejecting this adjustment.'}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div className="space-y-2">
                    <Label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                        Rejection reason
                    </Label>
                    <Textarea
                        value={form.data.rejection_reason}
                        onChange={(event) => form.setData('rejection_reason', event.target.value)}
                        className="min-h-24 rounded-xl border-border bg-card"
                    />
                    {form.errors.rejection_reason ? (
                        <div className="text-xs font-medium text-destructive">{form.errors.rejection_reason}</div>
                    ) : null}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl">Cancel</AlertDialogCancel>
                    <Button
                        className="rounded-xl"
                        onClick={submit}
                        disabled={form.processing || !form.data.rejection_reason.trim()}
                    >
                        Reject
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
