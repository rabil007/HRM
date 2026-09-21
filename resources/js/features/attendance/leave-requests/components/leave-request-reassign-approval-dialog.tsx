import { useForm } from '@inertiajs/react';
import { reassignApproval } from '@/actions/App/Http/Controllers/Attendance/LeaveRequestController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import { firstValidationError } from '@/lib/first-validation-error';
import { toast } from '@/lib/toast';
import type {
    LeaveRequest,
    LeaveRequestApproval,
    LeaveReassignmentApproverCandidate,
} from '../types';

export function LeaveRequestReassignApprovalDialog({
    open,
    onOpenChange,
    leaveRequest,
    approval,
    candidates,
    onSuccess,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    leaveRequest: LeaveRequest | null;
    approval: LeaveRequestApproval | null;
    candidates: LeaveReassignmentApproverCandidate[];
    onSuccess: () => void;
}) {
    const currentApproverId = approval?.approver_employee?.id ?? null;
    const currentApproverName =
        approval?.approver_employee?.name ??
        approval?.approver_user?.name ??
        '—';

    const form = useForm<{
        new_approver_employee_id: number | '';
        expected_approver_employee_id: number | '';
        expected_approval_id: number | '';
        reassignment_reason: string;
    }>({
        new_approver_employee_id: '',
        expected_approver_employee_id: currentApproverId ?? '',
        expected_approval_id: approval?.id ?? '',
        reassignment_reason: '',
    });

    const bagErrors = form.errors as Record<string, string | undefined>;

    const submit = () => {
        if (
            !leaveRequest ||
            !approval ||
            !form.data.new_approver_employee_id ||
            !(form.data.expected_approver_employee_id || currentApproverId) ||
            !(form.data.expected_approval_id || approval.id) ||
            !form.data.reassignment_reason.trim()
        ) {
            return;
        }

        form.setData({
            ...form.data,
            expected_approver_employee_id:
                form.data.expected_approver_employee_id ||
                currentApproverId ||
                '',
            expected_approval_id: form.data.expected_approval_id || approval.id,
        });

        form.put(reassignApproval.url(leaveRequest.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                form.clearErrors();
                onOpenChange(false);
                onSuccess();
            },
            onError: (errors: Record<string, string>) => {
                toast.error(
                    firstValidationError(
                        errors,
                        'leave_request',
                        'Failed to reassign approval. Please try again.',
                    ),
                );
            },
        });
    };

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (next && approval) {
                    form.setData({
                        new_approver_employee_id: '',
                        expected_approver_employee_id: currentApproverId ?? '',
                        expected_approval_id: approval.id,
                        reassignment_reason: '',
                    });
                    form.clearErrors();
                }

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
                        Reassign current approval
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        Only the current pending approval step will be
                        reassigned. Previous approvals and the original approval
                        policy history will be preserved.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                {approval ? (
                    <div className="space-y-3 rounded-xl border border-border/60 bg-muted/30 p-3 text-sm dark:border-white/6 dark:bg-white/4">
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">Step</span>
                            <span className="text-right font-semibold">
                                {approval.sequence}
                                {approval.policy_step_label
                                    ? ` — ${approval.policy_step_label}`
                                    : ''}
                            </span>
                        </div>
                        <div className="flex justify-between gap-3">
                            <span className="text-muted-foreground">
                                Current approver
                            </span>
                            <span className="text-right font-semibold">
                                {currentApproverName}
                            </span>
                        </div>
                    </div>
                ) : null}

                <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-900 dark:text-amber-100">
                    Leave balances, dates, and completed approval decisions are
                    not changed by this action.
                </div>

                <div className="space-y-2">
                    <Label
                        htmlFor="new_approver_employee_id"
                        className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                    >
                        Reassign to
                    </Label>
                    <AppSelect
                        value={String(form.data.new_approver_employee_id || '')}
                        onValueChange={(value) =>
                            form.setData(
                                'new_approver_employee_id',
                                value ? Number(value) : '',
                            )
                        }
                        variant="card"
                        placeholder="Select eligible approver"
                    >
                        {candidates.map((candidate) => (
                            <AppSelectItem
                                key={candidate.id}
                                value={String(candidate.id)}
                            >
                                {candidate.employee_no
                                    ? `${candidate.employee_no} — ${candidate.name ?? 'Employee'}`
                                    : (candidate.name ?? 'Employee')}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                    {form.errors.new_approver_employee_id ? (
                        <div className="text-xs font-medium text-destructive">
                            {form.errors.new_approver_employee_id}
                        </div>
                    ) : null}
                </div>

                <div className="space-y-2">
                    <Label
                        htmlFor="reassignment_reason"
                        className="text-xs font-semibold tracking-wider text-muted-foreground/70 uppercase"
                    >
                        Reason
                    </Label>
                    <Textarea
                        id="reassignment_reason"
                        value={form.data.reassignment_reason}
                        onChange={(e) =>
                            form.setData('reassignment_reason', e.target.value)
                        }
                        className="min-h-24 rounded-xl border-border bg-card"
                        placeholder="Explain why this approval is being reassigned..."
                    />
                    {form.errors.reassignment_reason ? (
                        <div className="text-xs font-medium text-destructive">
                            {form.errors.reassignment_reason}
                        </div>
                    ) : null}
                    {bagErrors.leave_request ? (
                        <div className="text-xs font-medium text-destructive">
                            {bagErrors.leave_request}
                        </div>
                    ) : null}
                </div>

                <AlertDialogFooter>
                    <AlertDialogCancel className="rounded-xl glass-card hover:bg-accent">
                        Keep current approver
                    </AlertDialogCancel>
                    <Button
                        className="rounded-xl"
                        onClick={submit}
                        disabled={
                            form.processing ||
                            !form.data.new_approver_employee_id ||
                            !form.data.reassignment_reason.trim() ||
                            candidates.length === 0
                        }
                    >
                        Confirm reassignment
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
