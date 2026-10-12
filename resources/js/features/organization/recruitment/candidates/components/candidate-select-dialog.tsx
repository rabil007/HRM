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
import type { CandidateDetail, CandidateIndexRow } from '../types';

function existingFeedback(
    candidate: CandidateIndexRow | CandidateDetail,
): string {
    const detail = candidate as CandidateDetail;

    if (detail.interview?.feedback) {
        return detail.interview.feedback;
    }

    return candidate.interview_feedback ?? '';
}

export function CandidateSelectDialog({
    candidate,
    open,
    onOpenChange,
}: {
    candidate: CandidateIndexRow | CandidateDetail | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    if (!open || !candidate) {
        return null;
    }

    return (
        <SelectDialogBody
            key={`${candidate.id}-${candidate.lock_version}`}
            candidate={candidate}
            onOpenChange={onOpenChange}
        />
    );
}

function SelectDialogBody({
    candidate,
    onOpenChange,
}: {
    candidate: CandidateIndexRow | CandidateDetail;
    onOpenChange: (open: boolean) => void;
}) {
    const priorFeedback = existingFeedback(candidate);
    const [feedback, setFeedback] = useState(priorFeedback);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const generalError =
        errors.candidate ||
        errors.lock_version ||
        errors.stage ||
        errors.interview_outcome ||
        errors.interview_feedback;

    const confirm = () => {
        if (processing) {
            return;
        }

        setProcessing(true);
        setErrors({});

        const trimmed = feedback.trim();

        // Only send feedback when the recruiter entered or changed a non-empty value.
        // Empty optional field must not overwrite existing feedback on the backend.
        const payload = {
            lock_version: candidate.lock_version,
            expected_stage: candidate.stage,
            expected_outcome: candidate.interview_outcome ?? '',
            ...(trimmed !== '' ? { interview_feedback: trimmed } : {}),
        };

        router.post(
            `/organization/recruitment/candidates/${candidate.id}/select`,
            payload,
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (pageErrors) => {
                    setErrors(pageErrors as Record<string, string>);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog
            open
            onOpenChange={(next) => {
                if (!next && !processing) {
                    onOpenChange(false);
                }
            }}
        >
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Mark Candidate Selected</DialogTitle>
                    <DialogDescription>
                        {candidate.name} · {candidate.position_title}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3">
                    <p className="rounded-lg border border-border/60 bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                        Interview → Selected. The candidate stays in Interview
                        with a Selected outcome until an Offer/JOL is prepared.
                    </p>

                    <div className="space-y-1.5">
                        <Label htmlFor="select-interview-feedback">
                            Interview Feedback (optional)
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            Record final interview observations with this
                            selection. Leave blank to keep any previously saved
                            feedback.
                        </p>
                        {priorFeedback.trim() !== '' ? (
                            <p className="text-xs text-muted-foreground">
                                Previously recorded feedback is shown below and
                                will be preserved if you leave this blank.
                            </p>
                        ) : null}
                        <Textarea
                            id="select-interview-feedback"
                            value={feedback}
                            rows={4}
                            aria-invalid={Boolean(errors.interview_feedback)}
                            onChange={(event) =>
                                setFeedback(event.target.value)
                            }
                        />
                        {errors.interview_feedback ? (
                            <p className="text-xs text-destructive">
                                {errors.interview_feedback}
                            </p>
                        ) : null}
                    </div>

                    {generalError ? (
                        <p className="text-xs text-destructive" role="alert">
                            {generalError}
                        </p>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={processing}
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={processing}
                        onClick={confirm}
                    >
                        {processing ? 'Selecting…' : 'Confirm Selection'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
