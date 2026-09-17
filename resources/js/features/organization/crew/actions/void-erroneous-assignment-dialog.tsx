import { useForm } from '@inertiajs/react';
import VoidCrewAssignmentController from '@/actions/App/Http/Controllers/Organization/VoidCrewAssignmentController';
import { ActionImpactPreview } from '@/components/action-impact-preview';
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
                    <AlertDialogTitle>Void Assignment</AlertDialogTitle>
                    <AlertDialogDescription>
                        Use only when this assignment or recorded movement was
                        entered by mistake. Voiding removes it from active
                        operational use while audit history is retained.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                {assignment ? (
                    <ActionImpactPreview
                        severity="destructive"
                        subject={[
                            assignment.assignment_no,
                            assignment.employee?.name,
                        ]
                            .filter(Boolean)
                            .join('\n')}
                        currentState={
                            assignment.current_phase
                                ? `${assignment.current_phase.code.toUpperCase()} · ${assignment.current_phase.label}`
                                : undefined
                        }
                        impacts={[
                            'The assignment is marked voided and removed from active operational use.',
                            'Audit history for the assignment remains retained.',
                            'Derived planning bars linked to this assignment are cleaned up according to existing void rules.',
                        ]}
                        warning="This assignment cannot be voided if it has already affected protected payroll, sea service, or a linked assignment. Use the appropriate correction or reversal workflow instead."
                    />
                ) : null}

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
                        Void Assignment
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
