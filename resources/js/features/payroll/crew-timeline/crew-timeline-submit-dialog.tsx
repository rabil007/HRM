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
import { submit as submitRoute } from '@/routes/payroll/crew-timeline';

export function CrewTimelineSubmitDialog({
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
        form.post(submitRoute.url([periodId, preparationId]), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Submit for Crewing Approval
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Submit this timeline preparation for supervisor review.
                        Generated lines cannot be edited after submission.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={form.processing}>
                        Cancel
                    </AlertDialogCancel>
                    <Button disabled={form.processing} onClick={submit}>
                        {form.processing ? 'Submitting…' : 'Submit'}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
