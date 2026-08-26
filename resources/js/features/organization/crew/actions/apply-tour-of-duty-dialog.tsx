import { useForm } from '@inertiajs/react';
import ApplyCrewTourOfDutyController from '@/actions/App/Http/Controllers/Organization/ApplyCrewTourOfDutyController';
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
import { formatDisplayDate } from '@/lib/format-date';
import type { CrewAssignmentDetail } from '../types';

export function ApplyTourOfDutyDialog({
    open,
    onOpenChange,
    assignment,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignment: CrewAssignmentDetail;
}) {
    const form = useForm({});

    const tourDays = assignment.current_rank_tour_days ?? null;
    const hasExistingSignoff = assignment.planned_signoff_at != null;

    const submit = (): void => {
        form.post(ApplyCrewTourOfDutyController.url(assignment.id), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
            },
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="max-w-lg glass-card">
                <AlertDialogHeader>
                    <AlertDialogTitle>Apply Tour of Duty?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Apply the current Rank Tour of Duty to this assignment.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div className="space-y-3 rounded-xl border border-border/60 bg-muted/30 p-3 text-sm dark:border-white/6 dark:bg-white/4">
                    <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                            Assignment
                        </span>
                        <span className="text-right font-semibold">
                            {assignment.assignment_no}
                        </span>
                    </div>
                    <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">Employee</span>
                        <span className="text-right font-semibold">
                            {assignment.employee?.name ?? '—'}
                        </span>
                    </div>
                    <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">Rank</span>
                        <span className="text-right font-semibold">
                            {assignment.rank?.name ?? '—'}
                        </span>
                    </div>
                    <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                            Tour of Duty
                        </span>
                        <span className="text-right font-semibold text-primary">
                            {tourDays ? `${tourDays} days` : '—'}
                        </span>
                    </div>
                    <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                            Actual Join
                        </span>
                        <span className="text-right font-semibold">
                            {formatDisplayDate(assignment.actual_join_at)}
                        </span>
                    </div>
                    <div className="flex justify-between gap-3">
                        <span className="text-muted-foreground">
                            Calculated Planned Sign-Off
                        </span>
                        <span className="text-right font-semibold">
                            {formatDisplayDate(
                                assignment.suggested_planned_signoff_at,
                            )}
                        </span>
                    </div>
                    {hasExistingSignoff ? (
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">
                                Existing Planned Sign-Off
                            </span>
                            <span className="text-right font-semibold">
                                {formatDisplayDate(
                                    assignment.planned_signoff_at,
                                )}
                            </span>
                        </div>
                    ) : null}
                </div>

                {hasExistingSignoff ? (
                    <div className="rounded-xl border border-blue-500/40 bg-blue-500/10 px-3 py-2 text-sm text-blue-800 dark:text-blue-200">
                        Existing Planned Sign-Off will not be overwritten.
                    </div>
                ) : (
                    <div className="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-800 dark:text-emerald-200">
                        Planned Sign-Off will be set to{' '}
                        {formatDisplayDate(
                            assignment.suggested_planned_signoff_at,
                        )}
                        .
                    </div>
                )}

                {form.errors && Object.keys(form.errors).length > 0 ? (
                    <div className="text-xs font-medium text-destructive">
                        {String(Object.values(form.errors)[0])}
                    </div>
                ) : null}

                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl glass-card hover:bg-accent">
                        Cancel
                    </AlertDialogCancel>
                    <Button
                        type="button"
                        className="rounded-xl"
                        onClick={submit}
                        disabled={form.processing}
                    >
                        Apply Tour of Duty
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
