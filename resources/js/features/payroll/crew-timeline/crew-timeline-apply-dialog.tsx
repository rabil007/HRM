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
import { apply as applyRoute } from '@/routes/payroll/crew-timeline';

export function CrewTimelineApplyDialog({
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
    const form = useForm({});

    const submit = (): void => {
        form.post(applyRoute.url([periodId, preparationId]), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Apply Approved Timeline to Timesheets
                    </AlertDialogTitle>
                    <AlertDialogDescription className="space-y-2">
                        <span className="block">
                            Operational day totals will be written to payroll
                            timesheets for this period.
                        </span>
                        <span className="block">
                            Overtime, additions, deductions, remarks, and salary
                            inputs will be preserved.
                        </span>
                        <span className="block">
                            The timeline will become Applied and read-only.
                            Replacing an applied snapshot requires a future
                            correction workflow.
                        </span>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {'preparation' in form.errors && form.errors.preparation ? (
                    <p className="text-sm text-destructive">
                        {String(form.errors.preparation)}
                    </p>
                ) : null}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={form.processing}>
                        Cancel
                    </AlertDialogCancel>
                    <Button disabled={form.processing} onClick={submit}>
                        {form.processing ? 'Applying…' : 'Apply to Timesheets'}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
