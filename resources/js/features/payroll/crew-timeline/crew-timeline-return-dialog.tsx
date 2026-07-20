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
import { returnMethod as returnRoute } from '@/routes/payroll/crew-timeline';

export function CrewTimelineReturnDialog({
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
        if (!form.data.decision_notes.trim()) {
            form.setError('decision_notes', 'Return notes are required.');

            return;
        }

        form.post(returnRoute.url([periodId, preparationId]), {
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
                    <AlertDialogTitle>Return timeline</AlertDialogTitle>
                    <AlertDialogDescription>
                        Returning keeps this version as history. Correct Crew
                        Operations data and prepare a new version to continue.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <div className="space-y-2">
                    <Label htmlFor="return-notes">Return notes</Label>
                    <Textarea
                        id="return-notes"
                        value={form.data.decision_notes}
                        onChange={(event) =>
                            form.setData('decision_notes', event.target.value)
                        }
                        rows={4}
                    />
                    {form.errors.decision_notes ? (
                        <p className="text-sm text-destructive">
                            {form.errors.decision_notes}
                        </p>
                    ) : null}
                </div>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={form.processing}>
                        Cancel
                    </AlertDialogCancel>
                    <Button
                        variant="destructive"
                        disabled={form.processing}
                        onClick={submit}
                    >
                        {form.processing ? 'Returning…' : 'Return'}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
