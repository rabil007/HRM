import type { CrewTimelineLine } from '../crew-timeline/types.ts';

const ISO_DATE_PREFIX = /^(\d{4})-(\d{2})-(\d{2})/;
const MONTH_NAMES = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
] as const;

export type CrewTimelineLineSummary = {
    lineCount: number;
    excludedLineCount: number;
    warningCount: number;
    blockingWarningCount: number;
};

export function summarizeCrewTimelineLines(
    lines: CrewTimelineLine[],
): CrewTimelineLineSummary {
    return lines.reduce<CrewTimelineLineSummary>(
        (summary, line) => ({
            lineCount: summary.lineCount + 1,
            excludedLineCount:
                summary.excludedLineCount +
                (line.pay_category === 'excluded' ? 1 : 0),
            warningCount: summary.warningCount + (line.warning ? 1 : 0),
            blockingWarningCount:
                summary.blockingWarningCount +
                (line.warning?.is_blocking ? 1 : 0),
        }),
        {
            lineCount: 0,
            excludedLineCount: 0,
            warningCount: 0,
            blockingWarningCount: 0,
        },
    );
}

export function formatCrewTimelineDate(
    value: string | null | undefined,
): string {
    if (!value) {
        return '—';
    }

    const trimmed = value.trim();
    const match = ISO_DATE_PREFIX.exec(trimmed);

    if (!match) {
        return trimmed;
    }

    const [, year, month, day] = match;
    const monthName = MONTH_NAMES[Number(month) - 1];

    if (!monthName) {
        return trimmed;
    }

    return `${day} ${monthName} ${year}`;
}

export function formatCrewTimelineDateRange(
    from: string | null | undefined,
    to: string | null | undefined,
): string {
    if (!from && !to) {
        return 'No planned dates';
    }

    if (from && to && from.slice(0, 10) === to.slice(0, 10)) {
        return formatCrewTimelineDate(from);
    }

    return `${formatCrewTimelineDate(from)} – ${formatCrewTimelineDate(to)}`;
}

export function formatCrewTimelineDays(value: string): string {
    const days = Number.parseFloat(value);

    if (!Number.isFinite(days)) {
        return value;
    }

    const formatted = new Intl.NumberFormat('en', {
        maximumFractionDigits: 2,
    }).format(days);

    return `${formatted} ${days === 1 ? 'day' : 'days'}`;
}
