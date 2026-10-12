import type { InertiaFormProps } from '@inertiajs/react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    interviewFieldError,
    interviewKnownGeneralError,
    interviewUnrenderedErrors,
} from '../lib/interview-form-errors';
import type {
    CandidateDetail,
    CandidateFormOptions,
    CandidateStage,
} from '../types';
import { CandidateOutcomeBadge } from './candidate-stage-badge';

type InterviewFormData = {
    interview_scheduled_at: string;
    interviewer_user_id: string;
    external_interviewer_name: string;
    interview_mode: string;
    interview_location: string;
    interview_notes: string;
    interview_feedback: string;
    lock_version: number;
    expected_stage: CandidateStage;
};

export function CandidateInterviewPanel({
    candidate,
    options,
    form,
    onSave,
}: {
    candidate: CandidateDetail;
    options: CandidateFormOptions;
    form: InertiaFormProps<InterviewFormData>;
    onSave: () => void;
}) {
    const interviewErrors = form.errors as Record<string, string>;
    const interviewGeneralError = interviewKnownGeneralError(interviewErrors);
    const interviewFallbackErrors = interviewUnrenderedErrors(interviewErrors);
    const showInterviewSuccess =
        form.recentlySuccessful && Object.keys(interviewErrors).length === 0;
    const canEdit =
        candidate.interview.can_edit &&
        candidate.can_update_interview &&
        candidate.parents_valid;
    const lockedReason = candidate.interview.edit_blocked_reason;

    return (
        <section
            id="interview"
            className="scroll-mt-4 rounded-xl border border-border/60 bg-card p-5"
        >
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div className="space-y-1">
                    <h2 className="text-sm font-semibold tracking-wide uppercase">
                        Interview
                    </h2>
                    <div className="flex flex-wrap items-center gap-2">
                        <CandidateOutcomeBadge
                            outcome={candidate.interview_outcome}
                            label={candidate.interview_outcome_label}
                        />
                        <span className="text-xs text-muted-foreground">
                            Selected is an interview outcome, not a separate
                            pipeline stage.
                        </span>
                    </div>
                </div>
                {canEdit ? (
                    <Button
                        size="sm"
                        onClick={onSave}
                        disabled={form.processing}
                    >
                        Save interview
                    </Button>
                ) : null}
            </div>

            {!canEdit && lockedReason ? (
                <p
                    className="mb-3 rounded-md border border-border/60 bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
                    role="status"
                >
                    {lockedReason}
                </p>
            ) : null}

            {interviewGeneralError ? (
                <p className="mb-3 text-sm text-destructive" role="alert">
                    {interviewGeneralError}
                </p>
            ) : null}
            {interviewFallbackErrors.length > 0 ? (
                <div
                    className="mb-3 space-y-1 text-sm text-destructive"
                    role="alert"
                    aria-live="assertive"
                >
                    {interviewFallbackErrors.map((message) => (
                        <p key={message}>{message}</p>
                    ))}
                </div>
            ) : null}
            {showInterviewSuccess ? (
                <p
                    className="mb-3 text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    Interview information saved.
                </p>
            ) : null}

            <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor="interview_scheduled_at">Scheduled at</Label>
                    <Input
                        id="interview_scheduled_at"
                        type="datetime-local"
                        value={form.data.interview_scheduled_at}
                        disabled={!canEdit}
                        onChange={(event) =>
                            form.setData(
                                'interview_scheduled_at',
                                event.target.value,
                            )
                        }
                    />
                    <InputError
                        className="text-xs"
                        message={interviewFieldError(
                            interviewErrors,
                            'interview_scheduled_at',
                        )}
                    />
                </div>
                <div className="space-y-2">
                    <Label>Mode</Label>
                    <AppSelect
                        value={form.data.interview_mode || 'none'}
                        disabled={!canEdit}
                        onValueChange={(value) =>
                            form.setData(
                                'interview_mode',
                                value === 'none' ? '' : value,
                            )
                        }
                    >
                        <AppSelectItem value="none">Not set</AppSelectItem>
                        {options.interview_modes.map((mode) => (
                            <AppSelectItem key={mode.value} value={mode.value}>
                                {mode.label}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                    <InputError
                        className="text-xs"
                        message={interviewFieldError(
                            interviewErrors,
                            'interview_mode',
                        )}
                    />
                </div>
                <div className="space-y-2">
                    <Label>Internal interviewer</Label>
                    <AppSelect
                        value={form.data.interviewer_user_id || 'none'}
                        disabled={!canEdit}
                        onValueChange={(value) =>
                            form.setData(
                                'interviewer_user_id',
                                value === 'none' ? '' : value,
                            )
                        }
                    >
                        <AppSelectItem value="none">None</AppSelectItem>
                        {options.interviewers.map((user) => (
                            <AppSelectItem
                                key={user.id}
                                value={String(user.id)}
                            >
                                {user.name}
                            </AppSelectItem>
                        ))}
                    </AppSelect>
                    <InputError
                        className="text-xs"
                        message={interviewFieldError(
                            interviewErrors,
                            'interviewer_user_id',
                        )}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="external_interviewer_name">
                        External interviewer name
                    </Label>
                    <Input
                        id="external_interviewer_name"
                        value={form.data.external_interviewer_name}
                        disabled={!canEdit}
                        onChange={(event) =>
                            form.setData(
                                'external_interviewer_name',
                                event.target.value,
                            )
                        }
                    />
                    <InputError
                        className="text-xs"
                        message={interviewFieldError(
                            interviewErrors,
                            'external_interviewer_name',
                        )}
                    />
                </div>
                <div className="space-y-2 md:col-span-2">
                    <Label htmlFor="interview_location">
                        Location / meeting details
                    </Label>
                    <Input
                        id="interview_location"
                        value={form.data.interview_location}
                        disabled={!canEdit}
                        onChange={(event) =>
                            form.setData(
                                'interview_location',
                                event.target.value,
                            )
                        }
                    />
                    <InputError
                        className="text-xs"
                        message={interviewFieldError(
                            interviewErrors,
                            'interview_location',
                        )}
                    />
                </div>
                <div className="space-y-2 md:col-span-2">
                    <Label htmlFor="interview_notes">Preparation notes</Label>
                    <p className="text-xs text-muted-foreground">
                        Scheduling instructions and topics to discuss before the
                        interview.
                    </p>
                    <Textarea
                        id="interview_notes"
                        value={form.data.interview_notes}
                        disabled={!canEdit}
                        onChange={(event) =>
                            form.setData('interview_notes', event.target.value)
                        }
                    />
                    <InputError
                        className="text-xs"
                        message={interviewFieldError(
                            interviewErrors,
                            'interview_notes',
                        )}
                    />
                </div>
                {candidate.interview_outcome === null && canEdit ? (
                    <div className="space-y-2 rounded-lg border border-dashed border-border/70 bg-muted/20 px-3 py-2 md:col-span-2">
                        <p className="text-sm font-medium text-foreground">
                            Interview feedback
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Optional final feedback is captured when you Mark
                            Selected. It is not edited here while the outcome is
                            still pending.
                        </p>
                        {form.data.interview_feedback.trim() !== '' ? (
                            <div className="rounded-md border border-border/60 bg-background px-3 py-2 text-sm whitespace-pre-wrap">
                                {form.data.interview_feedback}
                            </div>
                        ) : null}
                    </div>
                ) : (
                    <div className="space-y-2 md:col-span-2">
                        <Label htmlFor="interview_feedback">
                            Interview feedback
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            Evaluation and observations recorded with or after
                            selection.
                        </p>
                        <Textarea
                            id="interview_feedback"
                            value={form.data.interview_feedback}
                            disabled
                            readOnly
                        />
                    </div>
                )}
            </div>
        </section>
    );
}
