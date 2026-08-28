import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import CompleteDocumentWorkflowTaskController from '@/actions/App/Http/Controllers/Organization/Documents/CompleteDocumentWorkflowTaskController';
import RejectDocumentWorkflowTaskController from '@/actions/App/Http/Controllers/Organization/Documents/RejectDocumentWorkflowTaskController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { WorkflowRequestDetail } from '@/features/organization/documents/workflow/types';

export function WorkflowDecisionDialog({
    request,
    mode,
    open,
    onOpenChange,
}: {
    request: WorkflowRequestDetail;
    mode: 'complete' | 'reject';
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const taskId = request.viewer_task?.id ?? null;
    const actionLabel = request.viewer_task?.action_label ?? 'Decision';

    const completeForm = useForm({ notes: '' });
    const rejectForm = useForm({ reason: '' });

    if (taskId === null) {
        return null;
    }

    const resolvedTaskId = taskId;

    function submitComplete() {
        completeForm.post(
            CompleteDocumentWorkflowTaskController.url({
                workflowTask: resolvedTaskId,
            }),
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    function submitReject() {
        rejectForm.post(
            RejectDocumentWorkflowTaskController.url({
                workflowTask: resolvedTaskId,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                },
            },
        );
    }

    const isReject = mode === 'reject';
    const title = isReject
        ? `Reject ${actionLabel.toLowerCase()}`
        : request.viewer_task?.action === 'approve'
          ? 'Approve document'
          : 'Complete review';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {isReject
                            ? 'Provide a reason for rejection. This may reject the workflow depending on stage rules.'
                            : 'Confirm your decision for this workflow task.'}
                    </DialogDescription>
                </DialogHeader>

                {isReject ? (
                    <div className="space-y-2 py-2">
                        <Label htmlFor="reject-reason">Rejection reason</Label>
                        <Textarea
                            id="reject-reason"
                            value={rejectForm.data.reason}
                            onChange={(event) =>
                                rejectForm.setData('reason', event.target.value)
                            }
                            rows={4}
                        />
                        {rejectForm.errors.reason ? (
                            <p className="text-sm text-destructive">
                                {rejectForm.errors.reason}
                            </p>
                        ) : null}
                        {(rejectForm.errors as Record<string, string>)
                            .workflow ? (
                            <p className="text-sm text-destructive">
                                {
                                    (
                                        rejectForm.errors as Record<
                                            string,
                                            string
                                        >
                                    ).workflow
                                }
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <div className="space-y-2 py-2">
                        <Label htmlFor="decision-notes">Notes (optional)</Label>
                        <Textarea
                            id="decision-notes"
                            value={completeForm.data.notes}
                            onChange={(event) =>
                                completeForm.setData(
                                    'notes',
                                    event.target.value,
                                )
                            }
                            rows={3}
                        />
                        {(completeForm.errors as Record<string, string>)
                            .workflow ? (
                            <p className="text-sm text-destructive">
                                {
                                    (
                                        completeForm.errors as Record<
                                            string,
                                            string
                                        >
                                    ).workflow
                                }
                            </p>
                        ) : null}
                    </div>
                )}

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    {isReject ? (
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={rejectForm.processing}
                            onClick={submitReject}
                        >
                            Reject
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            disabled={completeForm.processing}
                            onClick={submitComplete}
                        >
                            {request.viewer_task?.action === 'approve'
                                ? 'Approve'
                                : 'Complete review'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function WorkflowDecisionButtons({
    request,
    canReview,
    canApprove,
}: {
    request: WorkflowRequestDetail;
    canReview: boolean;
    canApprove: boolean;
}) {
    const [mode, setMode] = useState<'complete' | 'reject' | null>(null);

    if (!request.viewer_task) {
        return null;
    }

    const allowed =
        request.viewer_task.action === 'approve' ? canApprove : canReview;

    if (!allowed) {
        return null;
    }

    return (
        <>
            <div className="flex flex-wrap gap-2">
                <Button type="button" onClick={() => setMode('complete')}>
                    {request.viewer_task.action === 'approve'
                        ? 'Approve'
                        : 'Complete review'}
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    onClick={() => setMode('reject')}
                >
                    Reject
                </Button>
            </div>
            {mode ? (
                <WorkflowDecisionDialog
                    request={request}
                    mode={mode}
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setMode(null);
                        }
                    }}
                />
            ) : null}
        </>
    );
}
