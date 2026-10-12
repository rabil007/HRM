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
    const canEdit = candidate.can_update && candidate.parents_valid;

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
                    <Label>Scheduled at</Label>
                    <Input
                        type="datetime-local"
                        value={form.data.interview_scheduled_at}
                        disabled={!candidate.can_update}
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
                        disabled={!candidate.can_update}
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
                        disabled={!candidate.can_update}
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
                    <Label>External interviewer name</Label>
                    <Input
                        value={form.data.external_interviewer_name}
                        disabled={!candidate.can_update}
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
                    <Label>Location / meeting details</Label>
                    <Input
                        value={form.data.interview_location}
                        disabled={!candidate.can_update}
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
                    <Label>Feedback</Label>
                    <Textarea
                        value={form.data.interview_feedback}
                        disabled={!candidate.can_update}
                        onChange={(event) =>
                            form.setData(
                                'interview_feedback',
                                event.target.value,
                            )
                        }
                    />
                    <InputError
                        className="text-xs"
                        message={interviewFieldError(
                            interviewErrors,
                            'interview_feedback',
                        )}
                    />
                </div>
            </div>
        </section>
    );
}
