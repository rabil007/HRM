import type { RecruitmentClockState } from '@/types/recruitment';

export const RECRUITMENT_CLOCK_STATE_LABELS: Record<
    RecruitmentClockState,
    string
> = {
    not_started: 'Not started',
    running: 'Running',
    paused: 'Paused (on hold)',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export function resolveRecruitmentClockSummary(input: {
    clockState: RecruitmentClockState;
    durationLabel: string | null;
    startedAtFormatted: string | null;
}): string {
    const stateLabel = RECRUITMENT_CLOCK_STATE_LABELS[input.clockState];

    if (input.clockState === 'not_started') {
        return stateLabel;
    }

    if (input.durationLabel) {
        return `${stateLabel} · ${input.durationLabel} active`;
    }

    if (input.startedAtFormatted) {
        return `${stateLabel} · since ${input.startedAtFormatted}`;
    }

    return stateLabel;
}

export function resolveActiveRecruitmentDurationDisplay(input: {
    durationLabel: string | null | undefined;
    isEstimated?: boolean | null;
    estimateNote?: string | null;
}): {
    label: string | null;
    showEstimated: boolean;
    estimateNote: string | null;
} {
    const label =
        typeof input.durationLabel === 'string' &&
        input.durationLabel.trim() !== ''
            ? input.durationLabel
            : null;

    const showEstimated = label !== null && input.isEstimated === true;
    const estimateNote =
        showEstimated &&
        typeof input.estimateNote === 'string' &&
        input.estimateNote.trim() !== ''
            ? input.estimateNote.trim()
            : null;

    return {
        label,
        showEstimated,
        estimateNote,
    };
}
