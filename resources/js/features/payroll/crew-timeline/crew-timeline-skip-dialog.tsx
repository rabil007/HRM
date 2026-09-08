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
import { employeeSkip } from '@/routes/payroll/crew-timeline';
import type { CrewTimelineEmployeeSummary } from './types';

export function CrewTimelineSkipDialog({
    open,
    onOpenChange,
    periodId,
    preparationId,
    employee,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    periodId: number;
    preparationId: number;
    employee: CrewTimelineEmployeeSummary | null;
}) {
    const form = useForm({ reason: '' });

    if (!employee) {
        return null;
    }

    const submit = (): void => {
        const trimmed = form.data.reason.trim();

        if (trimmed.length < 5) {
            form.setError(
                'reason',
                'A valid reason of at least 5 characters is required.',
            );

            return;
        }

        if (trimmed.length > 1000) {
            form.setError('reason', 'Reason cannot exceed 1000 characters.');

            return;
        }

        form.post(
            employeeSkip.url([periodId, preparationId, employee.employee_id]),
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    onOpenChange(false);
                },
            },
        );
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
            <AlertDialogContent className="max-w-lg glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Skip Crew Operations timeline data?
                    </AlertDialogTitle>
                    <AlertDialogDescription className="space-y-2 text-left">
                        <span>
                            This employee&apos;s Crew Operations movement data
                            will not be applied from this preparation.
                        </span>
                        <div className="space-y-1 rounded-md bg-muted/60 p-3 text-xs text-muted-foreground">
                            <p className="font-medium text-foreground">
                                You can later:
                            </p>
                            <ul className="list-disc space-y-0.5 pl-4">
                                <li>
                                    enter the employee&apos;s timesheet
                                    manually,
                                </li>
                                <li>
                                    import their timesheet through Excel, or
                                </li>
                                <li>exclude them when generating payroll.</li>
                            </ul>
                        </div>
                        <p className="text-xs text-muted-foreground italic">
                            Original Crew Operations movement data will not be
                            changed.
                        </p>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <div className="space-y-2">
                    <Label htmlFor="skip-reason">Reason *</Label>
                    <Textarea
                        id="skip-reason"
                        placeholder="Explain why this employee's timeline data is being skipped (e.g. movement correction pending next month)..."
                        value={form.data.reason}
                        onChange={(event) =>
                            form.setData('reason', event.target.value)
                        }
                        rows={3}
                    />
                    {form.errors.reason ? (
                        <p className="text-sm text-destructive">
                            {form.errors.reason}
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
                        {form.processing ? 'Skipping…' : 'Skip Timeline Data'}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
