import type {
    CrewTimelineAssignmentSummary,
    CrewTimelineEmployeeSummary,
    CrewTimelineLine,
    CrewTimelinePhaseOccurrence,
} from '../crew-timeline/types.ts';

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

export type CrewTimelineModalSummary = {
    assignmentCount: number;
    operationalPhaseCount: number;
    payablePeriodCount: number;
    payableDays: number;
    blockingWarningCount: number;
    informationalWarningCount: number;
};

export type CrewTimelineAssignmentLinkDivider = {
    kind: 'vessel_transfer' | 'redeployment';
    label: string;
    fromAssignmentNumber: string | null;
    toAssignmentNumber: string | null;
    fromVessel: string | null;
    toVessel: string | null;
};

export type CrewTimelineAssignmentSection = {
    assignment: CrewTimelineAssignmentSummary;
    linkFromPrevious: CrewTimelineAssignmentLinkDivider | null;
};

/** @deprecated Prefer summarizeCrewTimelineEmployee for modal summaries. */
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

export function summarizeCrewTimelineEmployee(
    employee: CrewTimelineEmployeeSummary,
): CrewTimelineModalSummary {
    const assignments = employee.assignments ?? [];
    const phases = assignments.flatMap((assignment) => assignment.phases);
    const payablePeriodCount = phases.reduce((count, phase) => {
        const payableLines = phase.payroll_lines.filter(
            (line) =>
                Number.parseFloat(line.days) > 0 &&
                line.pay_category !== null &&
                line.pay_category !== 'excluded',
        );

        return count + payableLines.length;
    }, 0);

    return {
        assignmentCount: employee.assignment_count ?? assignments.length,
        operationalPhaseCount: phases.filter((phase) => phase.is_operational)
            .length,
        payablePeriodCount,
        payableDays: employee.total_payable_days,
        blockingWarningCount: employee.blocking_warning_count,
        informationalWarningCount: employee.informational_warning_count,
    };
}

export function buildCrewTimelineAssignmentSections(
    assignments: CrewTimelineAssignmentSummary[],
): CrewTimelineAssignmentSection[] {
    return assignments.map((assignment, index) => {
        const previous = index > 0 ? assignments[index - 1] : null;
        const linkSource =
            assignment.source === 'vessel_transfer' ||
            assignment.source === 'redeployment'
                ? assignment.source
                : null;

        const linkFromPrevious: CrewTimelineAssignmentLinkDivider | null =
            previous &&
            linkSource &&
            assignment.previous_assignment_id !== null &&
            previous.id !== null &&
            assignment.previous_assignment_id === previous.id
                ? {
                      kind: linkSource,
                      label:
                          linkSource === 'vessel_transfer'
                              ? 'Vessel Transfer'
                              : 'Redeployment',
                      fromAssignmentNumber:
                          previous.assignment_number ??
                          assignment.previous_assignment_number,
                      toAssignmentNumber: assignment.assignment_number,
                      fromVessel: previous.vessel ?? assignment.previous_vessel,
                      toVessel: assignment.vessel,
                  }
                : null;

        return {
            assignment,
            linkFromPrevious,
        };
    });
}

export function phaseOccurrenceTitle(
    phase: CrewTimelinePhaseOccurrence,
): string {
    const code = phase.phase_code_display ?? phase.phase_code?.toUpperCase();
    const label = phase.phase_label ?? 'Unlabelled phase';
    const base = code ? `${code} — ${label}` : label;

    if (phase.occurrence !== null && phase.occurrence_count > 1) {
        return `${base} · Occurrence ${phase.occurrence}`;
    }

    return base;
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
    emptyLabel = 'No planned dates',
): string {
    if (!from && !to) {
        return emptyLabel;
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

export function formatAssignmentCountLabel(count: number): string {
    return `${count} ${count === 1 ? 'assignment' : 'assignments'} included in this payroll period`;
}
