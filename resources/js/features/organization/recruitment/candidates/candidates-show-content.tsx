import { useForm } from '@inertiajs/react';
import { Download, FileWarning, Pencil } from 'lucide-react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { RecentActivityCard } from '@/components/recent-activity-card';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { RecruitmentBreadcrumbs } from '../components/recruitment-breadcrumbs';
import { CandidateDetailSidebar } from './components/candidate-detail-sidebar';
import { CandidateFormSheet } from './components/candidate-form-sheet';
import { CandidateInterviewPanel } from './components/candidate-interview-panel';
import { CandidateJoiningPanel } from './components/candidate-joining-panel';
import { CandidateMovementActions } from './components/candidate-movement-actions';
import { CandidateMovementHistory } from './components/candidate-movement-history';
import { CandidateOfferPanel } from './components/candidate-offer-panel';
import { CandidatePipelineProgress } from './components/candidate-pipeline-progress';
import {
    CandidateOfferStatusBadge,
    CandidateOutcomeBadge,
    CandidateStageBadge,
} from './components/candidate-stage-badge';
import { CandidateStageMoveDialog } from './components/candidate-stage-move-dialog';
import type { StageMoveIntent } from './components/candidate-stage-move-dialog';
import { CandidateWorkspaceSection } from './components/candidate-workspace-section';
import { candidateFormFromRow } from './lib/candidate-form';
import {
    hasInterviewDetails,
    resolveWorkspaceFocus,
    shouldPrioritizeInterview,
    shouldPrioritizeJoined,
    shouldPrioritizeOffer,
    shouldPrioritizeRejected,
    shouldShowJoiningPanel,
    shouldShowOfferPanel,
    workspaceSectionTitle,
} from './lib/candidate-stage-workspace';
import { daysInStageLabel } from './lib/kanban-stage-meta';
import type { CandidateFormData, CandidateShowProps } from './types';

export function CandidatesShowContent({
    candidate,
    options,
    can,
    recent_activity,
    can_view_audit,
}: CandidateShowProps) {
    const [editOpen, setEditOpen] = useState(false);
    const [moveIntent, setMoveIntent] = useState<StageMoveIntent | null>(null);
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

    const focus = resolveWorkspaceFocus(candidate.stage);
    const ageLabel = daysInStageLabel(candidate.days_in_stage);
    const prioritizeInterview = shouldPrioritizeInterview(candidate);
    const prioritizeOffer = shouldPrioritizeOffer(candidate);
    const prioritizeJoined = shouldPrioritizeJoined(candidate);
    const prioritizeRejected = shouldPrioritizeRejected(candidate);
    const showOffer = shouldShowOfferPanel(candidate);
    const showJoining = shouldShowJoiningPanel(candidate);
    const showInterview = prioritizeInterview || hasInterviewDetails(candidate);
    const canViewMovementHistory = can.view_audit || can_view_audit;

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
                onFinish: () => interviewForm.transform((data) => data),
            },
        );
    };

    const interviewPanel = showInterview ? (
        <CandidateInterviewPanel
            candidate={candidate}
            options={options}
            form={interviewForm}
            onSave={saveInterview}
        />
    ) : null;

    const offerPanel = showOffer ? (
        <CandidateOfferPanel candidate={candidate} options={options} />
    ) : null;

    const joiningPanel = showJoining ? (
        <CandidateJoiningPanel candidate={candidate} />
    ) : null;

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
                kicker="Recruitment"
                title={candidate.name}
                description={
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                        <span>
                            {candidate.line?.position_title ||
                                candidate.position_title}
                        </span>
                        <span aria-hidden>·</span>
                        <span>
                            {candidate.requirement?.requirement_number ||
                                candidate.requirement_number}
                        </span>
                        {candidate.requirement?.client_name ? (
                            <>
                                <span aria-hidden>·</span>
                                <span>{candidate.requirement.client_name}</span>
                            </>
                        ) : null}
                    </span>
                }
                backHref="/organization/recruitment/candidates"
                backLabel="Candidates"
                badges={
                    <div className="flex flex-wrap items-center gap-1.5">
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
                        {ageLabel ? (
                            <Badge
                                variant="outline"
                                className="h-6 px-2 text-[11px] font-normal text-muted-foreground"
                            >
                                {ageLabel}
                            </Badge>
                        ) : null}
                    </div>
                }
                actions={
                    <div className="flex flex-col items-stretch gap-2 sm:items-end">
                        <CandidateMovementActions
                            candidate={candidate}
                            onRequestMove={setMoveIntent}
                        />
                        <div className="flex flex-wrap justify-end gap-2">
                            {candidate.can_update ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={openEdit}
                                >
                                    <Pencil className="mr-2 h-3.5 w-3.5" />
                                    Edit profile
                                </Button>
                            ) : null}
                            {candidate.has_cv && candidate.can_download_cv ? (
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={`/organization/recruitment/candidates/${candidate.id}/cv`}
                                    >
                                        <Download className="mr-2 h-3.5 w-3.5" />
                                        Download CV
                                    </a>
                                </Button>
                            ) : null}
                            {!candidate.has_cv ? (
                                <span className="inline-flex items-center gap-1.5 text-xs text-amber-800 dark:text-amber-300">
                                    <FileWarning className="h-3.5 w-3.5" />
                                    No CV
                                </span>
                            ) : null}
                        </div>
                    </div>
                }
                className="mb-4"
            />

            <div className="mb-6 space-y-3">
                <CandidatePipelineProgress stage={candidate.stage} />
                {!candidate.parents_valid ? (
                    <p className="rounded-lg border border-amber-500/30 bg-amber-50/70 px-3 py-2 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
                        Linked requirement or position line is missing or not
                        Open — workflow actions are disabled until parents are
                        restored.
                    </p>
                ) : null}
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="min-w-0 space-y-6 lg:col-span-2">
                    <CandidateWorkspaceSection
                        title={workspaceSectionTitle(focus)}
                        description="Current-stage workflow and required actions."
                    >
                        {prioritizeRejected ? (
                            <section className="rounded-xl border border-rose-500/30 bg-rose-50/50 p-5 dark:bg-rose-950/20">
                                <h3 className="text-sm font-semibold text-rose-900 dark:text-rose-100">
                                    Candidate rejected
                                </h3>
                                <dl className="mt-3 space-y-2 text-sm">
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Reason
                                        </dt>
                                        <dd className="whitespace-pre-wrap">
                                            {candidate.rejection_reason || '—'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-muted-foreground">
                                            Previous stage
                                        </dt>
                                        <dd>
                                            {candidate.pre_rejection_stage ||
                                                '—'}
                                        </dd>
                                    </div>
                                </dl>
                                <p className="mt-3 text-xs text-muted-foreground">
                                    Reopen restores the recorded pre-rejection
                                    stage. Use the header action when
                                    authorized.
                                </p>
                            </section>
                        ) : null}

                        {(focus === 'applied' || focus === 'screening') &&
                        !prioritizeRejected ? (
                            <section className="rounded-xl border border-border/60 bg-card p-5">
                                <p className="text-sm text-muted-foreground">
                                    {focus === 'applied'
                                        ? 'Review the candidate profile and CV, then move to Screening when ready.'
                                        : 'Complete screening review, then move to Interview or correct back to Applied if needed.'}
                                </p>
                                {!candidate.has_cv ? (
                                    <p className="mt-3 inline-flex items-start gap-2 text-sm text-amber-800 dark:text-amber-300">
                                        <FileWarning className="mt-0.5 h-4 w-4 shrink-0" />
                                        No CV is on file for this candidate.
                                    </p>
                                ) : null}
                            </section>
                        ) : null}

                        {prioritizeInterview ? interviewPanel : null}
                        {prioritizeOffer ? (
                            <>
                                {offerPanel}
                                {joiningPanel}
                            </>
                        ) : null}
                        {prioritizeJoined ? joiningPanel : null}
                    </CandidateWorkspaceSection>

                    {!prioritizeInterview && interviewPanel ? (
                        <CandidateWorkspaceSection
                            title="Interview details"
                            description="Historical or supporting interview information."
                            defaultOpen={hasInterviewDetails(candidate)}
                        >
                            {interviewPanel}
                        </CandidateWorkspaceSection>
                    ) : null}

                    {!prioritizeOffer && offerPanel ? (
                        <CandidateWorkspaceSection
                            title="Offer / JOL"
                            description="Offer history and documents."
                            defaultOpen={false}
                        >
                            {offerPanel}
                        </CandidateWorkspaceSection>
                    ) : null}

                    {!prioritizeOffer && !prioritizeJoined && joiningPanel ? (
                        <CandidateWorkspaceSection
                            title="Joining"
                            description="Joining readiness and confirmation."
                            defaultOpen={false}
                        >
                            {joiningPanel}
                        </CandidateWorkspaceSection>
                    ) : null}

                    {canViewMovementHistory ? (
                        <section className="rounded-xl border border-border/60 bg-card p-5">
                            <h2 className="mb-3 text-sm font-semibold tracking-wide uppercase">
                                Recruitment movement history
                            </h2>
                            <CandidateMovementHistory
                                events={candidate.movement_history}
                            />
                        </section>
                    ) : null}

                    {can_view_audit ? (
                        <RecentActivityCard
                            description="Profile and related audit changes (separate from recruitment stage movements)."
                            items={
                                recent_activity as unknown as RecentActivityItem[]
                            }
                        />
                    ) : null}
                </div>

                <CandidateDetailSidebar candidate={candidate} />
            </div>

            <CandidateFormSheet
                open={editOpen}
                onOpenChange={setEditOpen}
                form={profileForm}
                options={options}
                editing={candidate}
                onSubmit={submitProfile}
                duplicateMessage={duplicateMessage}
            />

            <CandidateStageMoveDialog
                intent={moveIntent}
                onClose={() => setMoveIntent(null)}
                interviewOptions={{
                    interview_modes: options.interview_modes,
                    interviewers: options.interviewers,
                }}
            />
        </Main>
    );
}
