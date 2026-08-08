import { useForm } from '@inertiajs/react';
import VoidCrewAssignmentController from '@/actions/App/Http/Controllers/Organization/VoidCrewAssignmentController';
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
import type { CrewAssignmentDetail, CrewAssignmentListItem } from '../types';

type VoidableAssignment = Pick<
    CrewAssignmentDetail | CrewAssignmentListItem,
    'id' | 'assignment_no' | 'employee' | 'current_phase' | 'status_label'
>;

export function VoidErroneousAssignmentDialog({
    open,
    onOpenChange,
    assignment,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    assignment: VoidableAssignment | null;
}) {
    const form = useForm<{
        void_reason: string;
    }>({
        void_reason: '',
    });

    const bagErrors = form.errors as Record<string, string | undefined>;

    const submit = (): void => {
        if (!assignment || !form.data.void_reason.trim()) {
            return;
        }

        form.post(VoidCrewAssignmentController.url(assignment.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.clearErrors();
                onOpenChange(false);
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
                        Void erroneous assignment?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        This action is only for assignments or recorded
                        movements entered by mistake. The assignment will be
                        removed from active operational use while its audit
                        history is retained.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                {assignment ? (
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
                            <span className="text-muted-foreground">
                                Employee
                            </span>
                            <span className="text-right font-semibold">
                                {assignment.employee?.name ?? '—'}
                            </span>
                        </div>
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">
                                Current phase
                            </span>
                            <span className="text-right font-semibold">
                                {assignment.current_phase
                                    ? `${assignment.current_phase.code.toUpperCase()} · ${assignment.current_phase.label}`
                                    : 'None'}
                            </span>
                        </div>
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">
                                Status
                            </span>
                            <span className="font-semibold tracking-wide uppercase">
                                {assignment.status_label}
                            </span>
                        </div>
                    </div>
                ) : null}

                <div className="rounded-xl border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    This assignment cannot be voided if it has already affected
                    protected payroll, sea service, or a linked assignment. Use
                    the appropriate correction or reversal workflow instead.
                </div>

                <div className="space-y-2">
                    <Label
                        htmlFor="void_reason"
                        className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                    >
                        Void reason <span className="text-destructive">*</span>
                    </Label>
                    <Textarea
                        id="void_reason"
                        value={form.data.void_reason}
                        onChange={(e) =>
                            form.setData('void_reason', e.target.value)
                        }
                        className="min-h-24 rounded-xl border-border bg-card"
                        placeholder="Entered by mistake / wrong employee / duplicate assignment"
                        required
                        aria-required="true"
                    />
                    {form.errors.void_reason ? (
                        <div className="text-xs font-medium text-destructive">
                            {form.errors.void_reason}
                        </div>
                    ) : null}
                    {bagErrors.void ? (
                        <div className="text-xs font-medium text-destructive">
                            {bagErrors.void}
                        </div>
                    ) : null}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl glass-card hover:bg-accent">
                        Keep assignment
                    </AlertDialogCancel>
                    <Button
                        variant="destructive"
                        className="rounded-xl"
                        onClick={submit}
                        disabled={
                            form.processing || !form.data.void_reason.trim()
                        }
                    >
                        Void erroneous assignment
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
