import type { CandidateDetail, CandidateStage } from '../types.ts';
import { CANDIDATE_ACTIVE_PIPELINE_STAGES } from '../types.ts';

export type PipelineStepStatus =
    | 'complete'
    | 'current'
    | 'upcoming'
    | 'rejected';

export type PipelineStep = {
    stage: CandidateStage;
    label: string;
    status: PipelineStepStatus;
};

/**
 * Map active candidate stage onto the five-step recruiter pipeline.
 * Historical `joining` rows are treated as Offer/JOL for workspace focus.
 */
export function resolveWorkspaceFocus(
    stage: CandidateStage,
): Exclude<CandidateStage, 'joining'> {
    if (stage === 'joining') {
        return 'offer_jol';
    }

    return stage;
}

export function buildPipelineSteps(
    stage: CandidateStage,
    labels: Record<CandidateStage, string>,
): PipelineStep[] {
    const focus = resolveWorkspaceFocus(stage);

    if (focus === 'rejected') {
        return CANDIDATE_ACTIVE_PIPELINE_STAGES.map((pipelineStage) => ({
            stage: pipelineStage,
            label: labels[pipelineStage],
            status: 'upcoming' as const,
        }));
    }

    const currentIndex = CANDIDATE_ACTIVE_PIPELINE_STAGES.indexOf(focus);

    return CANDIDATE_ACTIVE_PIPELINE_STAGES.map((pipelineStage, index) => {
        let status: PipelineStepStatus = 'upcoming';

        if (index < currentIndex) {
            status = 'complete';
        } else if (index === currentIndex) {
            status = 'current';
        }

        return {
            stage: pipelineStage,
            label: labels[pipelineStage],
            status,
        };
    });
}

export function shouldPrioritizeInterview(candidate: CandidateDetail): boolean {
    return resolveWorkspaceFocus(candidate.stage) === 'interview';
}

export function shouldPrioritizeOffer(candidate: CandidateDetail): boolean {
    return resolveWorkspaceFocus(candidate.stage) === 'offer_jol';
}

/** Accepted Offer/JOL candidates should lead with joining preparation. */
export function shouldPrioritizeJoiningPrep(
    candidate: CandidateDetail,
): boolean {
    return (
        shouldPrioritizeOffer(candidate) &&
        (candidate.offer_status === 'accepted' ||
            candidate.current_offer?.status === 'accepted')
    );
}

export function shouldPrioritizeJoined(candidate: CandidateDetail): boolean {
    return resolveWorkspaceFocus(candidate.stage) === 'joined';
}

export function shouldPrioritizeRejected(candidate: CandidateDetail): boolean {
    return resolveWorkspaceFocus(candidate.stage) === 'rejected';
}

export function hasInterviewDetails(candidate: CandidateDetail): boolean {
    const interview = candidate.interview;

    return Boolean(
        interview.scheduled_at ||
        interview.mode ||
        interview.interviewer_user_id ||
        interview.external_interviewer_name ||
        interview.location ||
        interview.notes ||
        interview.feedback ||
        candidate.interview_outcome,
    );
}

export function shouldShowOfferPanel(candidate: CandidateDetail): boolean {
    return (
        shouldPrioritizeOffer(candidate) ||
        candidate.current_offer !== null ||
        candidate.can_prepare_offer ||
        candidate.offer_history.length > 0
    );
}

export function shouldShowJoiningPanel(candidate: CandidateDetail): boolean {
    const hasAcceptedOffer = candidate.current_offer?.status === 'accepted';

    return (
        shouldPrioritizeJoined(candidate) ||
        (shouldPrioritizeOffer(candidate) && hasAcceptedOffer) ||
        hasAcceptedOffer
    );
}

export function workspaceSectionTitle(
    focus: ReturnType<typeof resolveWorkspaceFocus>,
): string {
    switch (focus) {
        case 'applied':
            return 'Application review';
        case 'screening':
            return 'Screening';
        case 'interview':
            return 'Interview';
        case 'offer_jol':
            return 'Offer / JOL';
        case 'joined':
            return 'Joined';
        case 'rejected':
            return 'Rejected';
        default:
            return 'Workflow';
    }
}
