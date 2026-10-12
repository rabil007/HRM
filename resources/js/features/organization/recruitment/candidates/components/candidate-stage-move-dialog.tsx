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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type {
    CandidateIndexRow,
    CandidateMoveDestination,
    CandidateStage,
} from '../types';
import { CANDIDATE_STAGE_LABELS } from '../types';

export type StageMoveIntent = {
    candidate: CandidateIndexRow;
    destination: CandidateMoveDestination;
};

const INTERVIEW_MODES = [
    { value: 'in_person', label: 'In person' },
    { value: 'video', label: 'Video' },
    { value: 'phone', label: 'Phone' },
] as const;

export function CandidateStageMoveDialog({
    intent,
    onClose,
}: {
    intent: StageMoveIntent | null;
    onClose: () => void;
}) {
    if (!intent) {
        return null;
    }

    return (
        <StageMoveDialogBody
            key={`${intent.candidate.id}-${intent.destination.kind}-${intent.destination.stage}-${intent.candidate.lock_version}`}
            intent={intent}
            onClose={onClose}
        />
    );
}

function StageMoveDialogBody({
    intent,
    onClose,
}: {
    intent: StageMoveIntent;
    onClose: () => void;
}) {
    const { candidate, destination } = intent;
    const [reason, setReason] = useState('');
    const [actualJoiningDate, setActualJoiningDate] = useState('');
    const [interviewScheduledAt, setInterviewScheduledAt] = useState('');
    const [interviewMode, setInterviewMode] = useState('');
    const [externalInterviewer, setExternalInterviewer] = useState('');
    const [interviewLocation, setInterviewLocation] = useState('');
    const [interviewNotes, setInterviewNotes] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const fromLabel =
        CANDIDATE_STAGE_LABELS[candidate.stage as CandidateStage] ??
        candidate.stage_label;
    const toLabel =
        destination.stage === 'pre_rejection'
            ? 'previous stage'
            : (CANDIDATE_STAGE_LABELS[destination.stage as CandidateStage] ??
              destination.label);

    const isBackward = destination.kind === 'backward';
    const isForwardToInterview =
        destination.kind === 'forward' && destination.stage === 'interview';
    const isConfirmJoined = destination.kind === 'confirm_joined';
    const isCorrectJoined = destination.kind === 'correct_joined';
    const isReopen = destination.kind === 'reopen';
    const isPrepareOffer = destination.kind === 'prepare_offer';
    const reasonRequired = isBackward || isCorrectJoined || isReopen;

    const post = (url: string, data: Record<string, unknown>) => {
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
                onSuccess: () => onClose(),
                onError: (pageErrors) => {
                    setErrors(pageErrors as Record<string, string>);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const confirm = () => {
        if (isPrepareOffer) {
            window.location.href = `/organization/recruitment/candidates/${candidate.id}#offer-jol`;
            onClose();

            return;
        }

        if (destination.kind === 'forward') {
            const payload: Record<string, unknown> = {};

            if (reason.trim() !== '') {
                payload.reason = reason.trim();
            }

            if (isForwardToInterview) {
                if (interviewScheduledAt) {
                    payload.interview_scheduled_at = interviewScheduledAt;
                }

                if (interviewMode) {
                    payload.interview_mode = interviewMode;
                }

                if (externalInterviewer.trim()) {
                    payload.external_interviewer_name =
                        externalInterviewer.trim();
                }

                if (interviewLocation.trim()) {
                    payload.interview_location = interviewLocation.trim();
                }

                if (interviewNotes.trim()) {
                    payload.interview_feedback = interviewNotes.trim();
                }
            }

            post(
                `/organization/recruitment/candidates/${candidate.id}/move`,
                payload,
            );

            return;
        }

        if (isBackward) {
            post(
                `/organization/recruitment/candidates/${candidate.id}/correct-stage`,
                {
                    to_stage: destination.stage,
                    reason: reason.trim(),
                },
            );

            return;
        }

        if (isConfirmJoined) {
            post(
                `/organization/recruitment/candidates/${candidate.id}/confirm-joined`,
                {
                    actual_joining_date: actualJoiningDate,
                    notes: reason.trim() || null,
                },
            );

            return;
        }

        if (isCorrectJoined) {
            post(
                `/organization/recruitment/candidates/${candidate.id}/correct-joining`,
                { reason: reason.trim() },
            );

            return;
        }

        if (isReopen) {
            post(
                `/organization/recruitment/candidates/${candidate.id}/reopen`,
                { reason: reason.trim() },
            );
        }
    };

    const generalError =
        errors.candidate ||
        errors.lock_version ||
        errors.stage ||
        errors.to_stage ||
        errors.interview_outcome;

    const confirmDisabled =
        processing ||
        (reasonRequired && reason.trim().length < 3) ||
        (isConfirmJoined && actualJoiningDate.trim() === '');

    return (
        <Dialog
            open
            onOpenChange={(next) => {
                if (!next && !processing) {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{destination.label}</DialogTitle>
                    <DialogDescription>
                        {candidate.name}: {fromLabel} → {toLabel}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3">
                    {isBackward ? (
                        <p className="text-xs text-muted-foreground">
                            This is an audited correction. Interview history is
                            kept; any Selected outcome is cleared so the earlier
                            stage can continue.
                        </p>
                    ) : null}

                    {isForwardToInterview ? (
                        <div className="space-y-3 rounded-lg border border-border/60 p-3">
                            <p className="text-xs font-medium text-muted-foreground">
                                Optional interview details
                            </p>
                            <div className="space-y-1.5">
                                <Label htmlFor="interview_scheduled_at">
                                    Interview date/time
                                </Label>
                                <Input
                                    id="interview_scheduled_at"
                                    type="datetime-local"
                                    value={interviewScheduledAt}
                                    onChange={(event) =>
                                        setInterviewScheduledAt(
                                            event.target.value,
                                        )
                                    }
                                />
                                {errors.interview_scheduled_at ? (
                                    <p className="text-xs text-destructive">
                                        {errors.interview_scheduled_at}
                                    </p>
                                ) : null}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Interview mode</Label>
                                <Select
                                    value={interviewMode || undefined}
                                    onValueChange={setInterviewMode}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select mode" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {INTERVIEW_MODES.map((mode) => (
                                            <SelectItem
                                                key={mode.value}
                                                value={mode.value}
                                            >
                                                {mode.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.interview_mode ? (
                                    <p className="text-xs text-destructive">
                                        {errors.interview_mode}
                                    </p>
                                ) : null}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="external_interviewer">
                                    External interviewer
                                </Label>
                                <Input
                                    id="external_interviewer"
                                    value={externalInterviewer}
                                    onChange={(event) =>
                                        setExternalInterviewer(
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="interview_location">
                                    Location / meeting details
                                </Label>
                                <Input
                                    id="interview_location"
                                    value={interviewLocation}
                                    onChange={(event) =>
                                        setInterviewLocation(event.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="interview_notes">
                                    Interview notes
                                </Label>
                                <Textarea
                                    id="interview_notes"
                                    value={interviewNotes}
                                    onChange={(event) =>
                                        setInterviewNotes(event.target.value)
                                    }
                                />
                            </div>
                        </div>
                    ) : null}

                    {isConfirmJoined ? (
                        <div className="space-y-1.5">
                            <Label htmlFor="actual_joining_date">
                                Actual joining date
                            </Label>
                            <Input
                                id="actual_joining_date"
                                type="date"
                                value={actualJoiningDate}
                                onChange={(event) =>
                                    setActualJoiningDate(event.target.value)
                                }
                            />
                            {errors.actual_joining_date ? (
                                <p className="text-xs text-destructive">
                                    {errors.actual_joining_date}
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    {isPrepareOffer ? (
                        <p className="text-sm text-muted-foreground">
                            Continue on the candidate page to enter salary,
                            currency, proposed joining date, and other offer
                            details.
                        </p>
                    ) : null}

                    {!isPrepareOffer ? (
                        <div className="space-y-1.5">
                            <Label htmlFor="move_reason">
                                {reasonRequired
                                    ? 'Correction reason'
                                    : 'Note (optional)'}
                            </Label>
                            <Textarea
                                id="move_reason"
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                            />
                            {errors.reason ? (
                                <p className="text-xs text-destructive">
                                    {errors.reason}
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    {generalError ? (
                        <p className="text-xs text-destructive">
                            {generalError}
                        </p>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={processing}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={confirmDisabled}
                        onClick={confirm}
                    >
                        {isPrepareOffer ? 'Open offer workflow' : 'Confirm'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
