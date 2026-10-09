import { router } from '@inertiajs/react';
import { useState } from 'react';
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
import type { CandidateIndexRow } from '../types';

type ReasonAction = 'reject' | 'reopen' | null;

export function CandidateMovementActions({
    candidate,
}: {
    candidate: CandidateIndexRow;
}) {
    const [reasonAction, setReasonAction] = useState<ReasonAction>(null);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const post = (url: string, data: Record<string, unknown> = {}) => {
        setProcessing(true);
        setErrors({});
        router.post(
            url,
            {
                lock_version: candidate.lock_version,
                expected_stage: candidate.stage,
                expected_outcome: candidate.interview_outcome ?? '',
                ...data,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setReasonAction(null);
                    setReason('');
                    setErrors({});
                },
                onError: (pageErrors) => {
                    setErrors(pageErrors as Record<string, string>);
                },
                onFinish: () => {
                    setProcessing(false);
                },
            },
        );
    };

    const generalError =
        errors.candidate ||
        errors.lock_version ||
        errors.stage ||
        errors.interview_outcome ||
        errors.reason;

    return (
        <>
            <div className="flex flex-wrap gap-2">
                {candidate.can_move_forward ? (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        onClick={() =>
                            post(
                                `/organization/recruitment/candidates/${candidate.id}/move`,
                            )
                        }
                    >
                        {candidate.stage === 'applied'
                            ? 'Move to Screening'
                            : 'Move to Interview'}
                    </Button>
                ) : null}
                {candidate.can_select ? (
                    <Button
                        size="sm"
                        disabled={processing}
                        onClick={() =>
                            post(
                                `/organization/recruitment/candidates/${candidate.id}/select`,
                            )
                        }
                    >
                        Mark Selected
                    </Button>
                ) : null}
                {candidate.can_prepare_offer ? (
                    <Button size="sm" variant="outline" asChild>
                        <a
                            href={`/organization/recruitment/candidates/${candidate.id}#offer-jol`}
                        >
                            Prepare Offer
                        </a>
                    </Button>
                ) : null}
                {candidate.can_undo_selected ? (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        onClick={() =>
                            post(
                                `/organization/recruitment/candidates/${candidate.id}/undo-select`,
                            )
                        }
                    >
                        Undo Selected
                    </Button>
                ) : null}
                {candidate.can_reject ? (
                    <Button
                        size="sm"
                        variant="destructive"
                        disabled={processing}
                        onClick={() => {
                            setErrors({});
                            setReasonAction('reject');
                        }}
                    >
                        Reject
                    </Button>
                ) : null}
                {candidate.can_reopen ? (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        onClick={() => {
                            setErrors({});
                            setReasonAction('reopen');
                        }}
                    >
                        Reopen
                    </Button>
                ) : null}
            </div>

            {reasonAction === null && generalError ? (
                <p className="mt-2 text-xs text-destructive">{generalError}</p>
            ) : null}

            <Dialog
                open={reasonAction !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setReasonAction(null);
                        // Preserve reason text on dismiss so a failed submit can stay visible next open.
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {reasonAction === 'reopen'
                                ? 'Reopen rejected candidate'
                                : 'Reject candidate'}
                        </DialogTitle>
                        <DialogDescription>
                            {reasonAction === 'reopen'
                                ? 'Provide a reason. The candidate returns to the pre-rejection stage.'
                                : 'A reason is required and will be stored in movement history.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label>Reason</Label>
                        <Textarea
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                        />
                        {errors.reason ? (
                            <p className="text-xs text-destructive">
                                {errors.reason}
                            </p>
                        ) : null}
                        {errors.lock_version ||
                        errors.candidate ||
                        errors.stage ||
                        errors.interview_outcome ? (
                            <p className="text-xs text-destructive">
                                {errors.lock_version ||
                                    errors.candidate ||
                                    errors.stage ||
                                    errors.interview_outcome}
                            </p>
                        ) : null}
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setReasonAction(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant={
                                reasonAction === 'reject'
                                    ? 'destructive'
                                    : 'default'
                            }
                            disabled={processing || reason.trim() === ''}
                            onClick={() => {
                                if (reasonAction === 'reject') {
                                    post(
                                        `/organization/recruitment/candidates/${candidate.id}/reject`,
                                        { reason },
                                    );
                                } else if (reasonAction === 'reopen') {
                                    post(
                                        `/organization/recruitment/candidates/${candidate.id}/reopen`,
                                        { reason },
                                    );
                                }
                            }}
                        >
                            Confirm
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
