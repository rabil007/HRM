import type { RecruitmentClockState } from '@/types/recruitment';

const SECONDS_PER_DAY = 86400;

/**
 * Format active recruitment seconds as whole days for detail-page display.
 * Does not change the underlying duration calculation — display only.
 *
 * Rules: under 24h → "Less than 1 day"; otherwise ceil(seconds / 86400).
 */
export function formatRecruitmentDurationInDays(
    seconds: number | null | undefined,
): string {
    if (seconds === null || seconds === undefined) {
        return 'Not started';
    }

    if (seconds < SECONDS_PER_DAY) {
        return 'Less than 1 day';
    }

    const days = Math.ceil(seconds / SECONDS_PER_DAY);

    return days === 1 ? '1 day' : `${days} days`;
}

export function resolveActiveRecruitmentDurationDisplay(input: {
    clockState: RecruitmentClockState;
    activeSeconds: number | null | undefined;
    isEstimated?: boolean | null;
    estimateNote?: string | null;
}): {
    label: string;
    showEstimated: boolean;
    estimateNote: string | null;
} {
    if (
        input.clockState === 'not_started' ||
        input.activeSeconds === null ||
        input.activeSeconds === undefined
    ) {
        return {
            label: 'Not started',
            showEstimated: false,
            estimateNote: null,
        };
    }

    const daysPart = formatRecruitmentDurationInDays(input.activeSeconds);

    let label: string;

    switch (input.clockState) {
        case 'paused':
            label = `Paused at ${daysPart}`;
            break;
        case 'completed':
            label = `Completed in ${daysPart}`;
            break;
        case 'cancelled':
            label = `Cancelled after ${daysPart}`;
            break;
        default:
            label = daysPart;
            break;
    }

    const showEstimated = input.isEstimated === true;
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
