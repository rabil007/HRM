import { Link, useForm } from '@inertiajs/react';
import { Download, Pencil } from 'lucide-react';
import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { DetailsHeader } from '@/components/details-header';
import InputError from '@/components/input-error';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RecruitmentBreadcrumbs } from '../components/recruitment-breadcrumbs';
import { CandidateFormSheet } from './components/candidate-form-sheet';
import { CandidateMovementActions } from './components/candidate-movement-actions';
import { CandidateOfferPanel } from './components/candidate-offer-panel';
import {
    CandidateOfferStatusBadge,
    CandidateOutcomeBadge,
    CandidateStageBadge,
} from './components/candidate-stage-badge';
import { candidateFormFromRow } from './lib/candidate-form';
import {
    interviewFieldError,
    interviewKnownGeneralError,
    interviewUnrenderedErrors,
} from './lib/interview-form-errors';
import type { CandidateFormData, CandidateShowProps } from './types';

export function CandidatesShowContent({
    candidate,
    options,
    can,
    recent_activity,
    can_view_audit,
}: CandidateShowProps) {
    const [editOpen, setEditOpen] = useState(false);
    const [duplicateMessage, setDuplicateMessage] = useState<string | null>(
        null,
    );
    const profileForm = useForm<CandidateFormData>(
        candidateFormFromRow(candidate),
    );
    const interviewForm = useForm({
        interview_scheduled_at: candidate.interview.scheduled_at ?? '',
        interviewer_user_id: candidate.interview.interviewer_user_id
            ? String(candidate.interview.interviewer_user_id)
            : '',
        external_interviewer_name:
            candidate.interview.external_interviewer_name ?? '',
        interview_mode: candidate.interview.mode ?? '',
        interview_location: candidate.interview.location ?? '',
        interview_feedback: candidate.interview.feedback ?? '',
        lock_version: candidate.lock_version,
        expected_stage: candidate.stage,
    });

    const openEdit = () => {
        profileForm.setData(candidateFormFromRow(candidate));
        profileForm.clearErrors();
        setDuplicateMessage(null);
        setEditOpen(true);
    };

    const submitProfile = (forceDuplicate = false) => {
        profileForm.transform((data) => ({
            ...data,
            _method: 'put',
            ignore_duplicate_warning: forceDuplicate,
            nationality_id: data.nationality_id || null,
            source: data.source || null,
        }));
        profileForm.post(
            `/organization/recruitment/candidates/${candidate.id}`,
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    setEditOpen(false);
                    setDuplicateMessage(null);
                    profileForm.transform((data) => data);
                },
                onError: (errors) => {
                    if (errors.duplicate_warning) {
                        setDuplicateMessage(errors.duplicate_warning);
                    }
                },
                onFinish: () => profileForm.transform((data) => data),
            },
        );
    };

    const saveInterview = () => {
        interviewForm.transform((data) => ({
            ...data,
            interviewer_user_id: data.interviewer_user_id || null,
            interview_mode: data.interview_mode || null,
            interview_scheduled_at: data.interview_scheduled_at || null,
            lock_version: candidate.lock_version,
            expected_stage: candidate.stage,
        }));
        interviewForm.put(
            `/organization/recruitment/candidates/${candidate.id}/interview`,
            {
                preserveScroll: true,
                // Keep entered values on validation failure (Inertia default).
                // Success flash is handled globally; show inline only when recentlySuccessful.
                onFinish: () => interviewForm.transform((data) => data),
            },
        );
    };

    const interviewErrors = interviewForm.errors as Record<string, string>;
    const interviewGeneralError = interviewKnownGeneralError(interviewErrors);
    const interviewFallbackErrors = interviewUnrenderedErrors(interviewErrors);
    const showInterviewSuccess =
        interviewForm.recentlySuccessful &&
        Object.keys(interviewErrors).length === 0;

    return (
        <Main>
            <RecruitmentBreadcrumbs
                items={[
                    {
                        title: 'Candidates',
                        href: '/organization/recruitment/candidates',
                    },
                    { title: candidate.name },
                ]}
            />
            <DetailsHeader
                title={candidate.name}
                backHref="/organization/recruitment/candidates"
                backLabel="Candidates"
                actions={
                    <div className="flex flex-wrap gap-2">
                        {candidate.can_update ? (
                            <Button variant="outline" onClick={openEdit}>
                                <Pencil className="mr-2 h-4 w-4" />
                                Edit profile
                            </Button>
                        ) : null}
                        {candidate.can_download_cv ? (
                            <Button variant="outline" asChild>
                                <a
                                    href={`/organization/recruitment/candidates/${candidate.id}/cv`}
                                >
                                    <Download className="mr-2 h-4 w-4" />
                                    Download CV
                                </a>
                            </Button>
                        ) : null}
                    </div>
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <CandidateStageBadge
                    stage={candidate.stage}
                    label={candidate.stage_label}
                />
                <CandidateOutcomeBadge
                    outcome={candidate.interview_outcome}
                    label={candidate.interview_outcome_label}
                />
                <CandidateOfferStatusBadge
                    status={candidate.offer_status}
                    label={candidate.offer_status_label}
                />
                {!candidate.parents_valid ? (
                    <span className="text-sm text-amber-700 dark:text-amber-300">
                        Linked requirement/line is missing or not Open —
                        workflow actions are disabled.
                    </span>
                ) : null}
            </div>

            <div className="mb-6">
                <CandidateMovementActions candidate={candidate} />
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <section className="rounded-xl border border-border/60 p-5">
                    <h2 className="mb-3 text-sm font-semibold tracking-wide uppercase">
                        Profile
                    </h2>
                    <dl className="space-y-2 text-sm">
                        <div>
                            <dt className="text-muted-foreground">Email</dt>
                            <dd>{candidate.email || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Phone</dt>
                            <dd>{candidate.phone || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Nationality
                            </dt>
                            <dd>{candidate.nationality || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Source</dt>
                            <dd>{candidate.source_label || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Notes</dt>
                            <dd className="whitespace-pre-wrap">
                                {candidate.notes || '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">CV</dt>
                            <dd>
                                {candidate.cv_original_file_name ||
                                    (candidate.has_cv ? 'Uploaded' : '—')}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section className="rounded-xl border border-border/60 p-5">
                    <h2 className="mb-3 text-sm font-semibold tracking-wide uppercase">
                        Requirement / Position
                    </h2>
                    <dl className="space-y-2 text-sm">
                        <div>
                            <dt className="text-muted-foreground">
                                Requirement
                            </dt>
                            <dd>
                                {candidate.requirement ? (
                                    <Link
                                        href={`/organization/recruitment/requirements/${candidate.requirement.id}`}
                                        className="font-medium hover:underline"
                                    >
                                        {
                                            candidate.requirement
                                                .requirement_number
                                        }
                                    </Link>
                                ) : (
                                    candidate.requirement_number
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Position</dt>
                            <dd>
                                {candidate.line?.position_title ||
                                    candidate.position_title}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Client</dt>
                            <dd>{candidate.requirement?.client_name || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Assigned recruiter
                            </dt>
                            <dd>
                                {candidate.requirement?.assigned_to_name || '—'}
                            </dd>
                        </div>
                    </dl>
                </section>

                <CandidateOfferPanel candidate={candidate} options={options} />

                <section className="rounded-xl border border-border/60 p-5 lg:col-span-2">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-semibold tracking-wide uppercase">
                            Interview
                        </h2>
                        {candidate.can_update && candidate.parents_valid ? (
                            <Button
                                size="sm"
                                onClick={saveInterview}
                                disabled={interviewForm.processing}
                            >
                                Save interview
                            </Button>
                        ) : null}
                    </div>
                    {interviewGeneralError ? (
                        <p
                            className="mb-3 text-sm text-destructive"
                            role="alert"
                        >
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
                                value={
                                    interviewForm.data.interview_scheduled_at
                                }
                                disabled={!candidate.can_update}
                                onChange={(event) =>
                                    interviewForm.setData(
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
                                value={
                                    interviewForm.data.interview_mode || 'none'
                                }
                                disabled={!candidate.can_update}
                                onValueChange={(value) =>
                                    interviewForm.setData(
                                        'interview_mode',
                                        value === 'none' ? '' : value,
                                    )
                                }
                            >
                                <AppSelectItem value="none">
                                    Not set
                                </AppSelectItem>
                                {options.interview_modes.map((mode) => (
                                    <AppSelectItem
                                        key={mode.value}
                                        value={mode.value}
                                    >
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
                                value={
                                    interviewForm.data.interviewer_user_id ||
                                    'none'
                                }
                                disabled={!candidate.can_update}
                                onValueChange={(value) =>
                                    interviewForm.setData(
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
                                value={
                                    interviewForm.data.external_interviewer_name
                                }
                                disabled={!candidate.can_update}
                                onChange={(event) =>
                                    interviewForm.setData(
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
                                value={interviewForm.data.interview_location}
                                disabled={!candidate.can_update}
                                onChange={(event) =>
                                    interviewForm.setData(
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
                                value={interviewForm.data.interview_feedback}
                                disabled={!candidate.can_update}
                                onChange={(event) =>
                                    interviewForm.setData(
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

                {can.view_audit || can_view_audit ? (
                    <section className="rounded-xl border border-border/60 p-5 lg:col-span-2">
                        <h2 className="mb-3 text-sm font-semibold tracking-wide uppercase">
                            Movement history
                        </h2>
                        {candidate.movement_history.length > 0 ? (
                            <ul className="space-y-3">
                                {candidate.movement_history.map((event) => (
                                    <li
                                        key={event.id}
                                        className="border-b border-border/40 pb-3 text-sm last:border-0"
                                    >
                                        <div className="font-medium">
                                            {event.action_label}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {event.from_stage_label || '—'} →{' '}
                                            {event.to_stage_label}
                                            {event.to_outcome_label
                                                ? ` (${event.to_outcome_label})`
                                                : ''}
                                        </div>
                                        {event.reason ? (
                                            <div className="mt-1">
                                                {event.reason}
                                            </div>
                                        ) : null}
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {event.performed_by_name ||
                                                'System'}{' '}
                                            · {event.created_at}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No movement history yet.
                            </p>
                        )}
                    </section>
                ) : null}
            </div>

            {can_view_audit ? (
                <div className="mt-6">
                    <RecentActivityCard
                        description="Audit log of candidate profile and related changes."
                        items={
                            recent_activity as unknown as RecentActivityItem[]
                        }
                    />
                </div>
            ) : null}

            <CandidateFormSheet
                open={editOpen}
                onOpenChange={setEditOpen}
                form={profileForm}
                options={options}
                editing={candidate}
                onSubmit={submitProfile}
                duplicateMessage={duplicateMessage}
            />
        </Main>
    );
}
