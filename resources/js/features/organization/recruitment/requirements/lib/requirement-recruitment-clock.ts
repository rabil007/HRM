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
