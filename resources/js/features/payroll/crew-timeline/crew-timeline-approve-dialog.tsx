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
import { approve as approveRoute } from '@/routes/payroll/crew-timeline';

export function CrewTimelineApproveDialog({
    open,
    onOpenChange,
    periodId,
    preparationId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    periodId: number;
    preparationId: number;
}) {
    const form = useForm({ decision_notes: '' });

    const submit = (): void => {
        form.post(approveRoute.url([periodId, preparationId]), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    const handleOpenChange = (next: boolean): void => {
        if (!next) {
            form.reset();
            form.clearErrors();
        }

        onOpenChange(next);
    };

    return (
        <AlertDialog open={open} onOpenChange={handleOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>Approve timeline</AlertDialogTitle>
                    <AlertDialogDescription>
                        Approving locks this preparation as the active approved
                        timeline. Application to crew timesheets is not yet
                        available.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <div className="space-y-2">
                    <Label htmlFor="approve-notes">
                        Approval notes (optional)
                    </Label>
                    <Textarea
                        id="approve-notes"
                        value={form.data.decision_notes}
                        onChange={(event) =>
                            form.setData('decision_notes', event.target.value)
                        }
                        rows={3}
                    />
                    {form.errors.decision_notes ? (
                        <p className="text-sm text-destructive">
                            {form.errors.decision_notes}
                        </p>
                    ) : null}
                    {'preparation' in form.errors && form.errors.preparation ? (
                        <p className="text-sm text-destructive">
                            {String(form.errors.preparation)}
                        </p>
                    ) : null}
                </div>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={form.processing}>
                        Cancel
                    </AlertDialogCancel>
                    <Button disabled={form.processing} onClick={submit}>
                        {form.processing ? 'Approving…' : 'Approve'}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
