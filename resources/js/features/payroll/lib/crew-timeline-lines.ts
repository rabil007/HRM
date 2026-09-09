import type {
    CrewTimelineAssignmentSummary,
    CrewTimelineEmployeeSummary,
    CrewTimelineLine,
    CrewTimelinePhaseOccurrence,
    CrewTimelinePhaseWarning,
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
    fromAssignmentId: number | null;
    toAssignmentId: number | null;
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
                      fromAssignmentId: previous.id,
                      toAssignmentId: assignment.id,
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

export type CrewTimelinePayableCategoryKey =
    | 'sign_on_standby'
    | 'onsite'
    | 'sign_off_standby';

export type CrewTimelinePayrollSegment = {
    id: string;
    lineId: number;
    payCategory: CrewTimelinePayableCategoryKey;
    title: string;
    assignmentId: number | null;
    assignmentNumber: string | null;
    vessel: string | null;
    rank: string | null;
    client: string | null;
    actualStart: string | null;
    actualEnd: string | null;
    payrollFrom: string | null;
    payrollTo: string | null;
    days: string;
    warnings: CrewTimelinePhaseWarning[];
    linkFromPrevious: CrewTimelineAssignmentLinkDivider | null;
};

export type CrewTimelineExcludedSegment = {
    id: string;
    lineId: number;
    title: string;
    from: string | null;
    to: string | null;
    days: string;
};

export type CrewTimelineBreakdownWarning = {
    id: string;
    label: string;
    remarks: string | null;
    from: string | null;
    to: string | null;
    isBlocking: boolean;
};

export type CrewTimelinePayrollCategoryBreakdown = {
    key: CrewTimelinePayableCategoryKey;
    label: string;
    days: number;
    segments: CrewTimelinePayrollSegment[];
};

export type CrewTimelinePayrollBreakdown = {
    categories: CrewTimelinePayrollCategoryBreakdown[];
    excluded: CrewTimelineExcludedSegment[];
    warnings: CrewTimelineBreakdownWarning[];
    detectedPayableDays: number;
    appliedFromCrewOperationsDays: number;
};

const PAYABLE_CATEGORY_ORDER: Array<{
    key: CrewTimelinePayableCategoryKey;
    label: string;
}> = [
    { key: 'sign_on_standby', label: 'Sign-On Standby' },
    { key: 'onsite', label: 'Onsite' },
    { key: 'sign_off_standby', label: 'Sign-Off Standby' },
];

const FULL_MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
] as const;

export function formatCrewTimelineDayCount(days: number): string {
    const formatted = new Intl.NumberFormat('en', {
        maximumFractionDigits: 2,
    }).format(days);

    return `${formatted} ${days === 1 ? 'day' : 'days'}`;
}

export function formatCrewTimelineArrowRange(
    from: string | null | undefined,
    to: string | null | undefined,
    emptyLabel = '—',
): string {
    if (!from && !to) {
        return emptyLabel;
    }

    if (from && to && from.slice(0, 10) === to.slice(0, 10)) {
        return formatCrewTimelineDate(from);
    }

    return `${formatCrewTimelineDate(from)} → ${formatCrewTimelineDate(to)}`;
}

export function formatCrewTimelinePeriodLabel(period: {
    name: string;
    start_date: string | null;
}): string {
    const match = period.start_date
        ? ISO_DATE_PREFIX.exec(period.start_date.trim())
        : null;

    if (match) {
        const monthName = FULL_MONTH_NAMES[Number(match[2]) - 1];

        if (monthName) {
            return `${monthName} ${match[1]}`;
        }
    }

    return period.name.replace(/\s+-\s+Crew$/i, '').trim() || period.name;
}

function payrollPhaseTitle(
    phase: CrewTimelinePhaseOccurrence,
    line: CrewTimelineLine,
): string {
    const code =
        phase.phase_code_display ??
        line.phase_code?.toUpperCase() ??
        phase.phase_code?.toUpperCase() ??
        null;
    const label = phase.phase_label ?? line.phase_label ?? 'Unlabelled phase';
    const base = code ? `${code} · ${label}` : label;

    if (phase.occurrence !== null && phase.occurrence_count > 1) {
        return `${base} · Occurrence ${phase.occurrence}`;
    }

    return base;
}

function isPayableCategory(
    value: string | null,
): value is CrewTimelinePayableCategoryKey {
    return (
        value === 'sign_on_standby' ||
        value === 'onsite' ||
        value === 'sign_off_standby'
    );
}

function positiveDayCount(days: string): number {
    const parsed = Number.parseFloat(days);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

function categoryDays(
    employee: CrewTimelineEmployeeSummary,
    key: CrewTimelinePayableCategoryKey,
): number {
    switch (key) {
        case 'sign_on_standby':
            return employee.sign_on_standby_days;
        case 'onsite':
            return employee.onsite_days;
        case 'sign_off_standby':
            return employee.sign_off_standby_days;
    }
}

function warningsForLine(
    phase: CrewTimelinePhaseOccurrence,
    line: CrewTimelineLine,
): CrewTimelinePhaseWarning[] {
    const matched = phase.warnings.filter(
        (warning) => warning.line_id === line.id,
    );

    if (matched.length > 0 || line.warning === null) {
        return matched;
    }

    return [
        {
            ...line.warning,
            remarks: line.remarks,
            from_date: line.from_date,
            to_date: line.to_date,
            line_id: line.id,
        },
    ];
}

export function buildCrewTimelinePayrollBreakdown(
    employee: CrewTimelineEmployeeSummary,
): CrewTimelinePayrollBreakdown {
    const sections = buildCrewTimelineAssignmentSections(
        employee.assignments ?? [],
    );
    const segmentsByCategory: Record<
        CrewTimelinePayableCategoryKey,
        CrewTimelinePayrollSegment[]
    > = {
        sign_on_standby: [],
        onsite: [],
        sign_off_standby: [],
    };
    const excluded: CrewTimelineExcludedSegment[] = [];
    const warnings: CrewTimelineBreakdownWarning[] = [];
    const seenWarnings = new Set<string>();
    const dividerShownFor = new Set<string>();

    const pushWarning = (warning: CrewTimelineBreakdownWarning): void => {
        if (seenWarnings.has(warning.id)) {
            return;
        }

        seenWarnings.add(warning.id);
        warnings.push(warning);
    };

    for (const section of sections) {
        const { assignment, linkFromPrevious } = section;

        for (const phase of assignment.phases) {
            for (const warning of phase.warnings) {
                pushWarning({
                    id: `${warning.line_id}-${warning.code}`,
                    label: warning.label,
                    remarks: warning.remarks,
                    from: warning.from_date,
                    to: warning.to_date,
                    isBlocking: warning.is_blocking,
                });
            }

            for (const line of phase.payroll_lines) {
                if (line.warning) {
                    pushWarning({
                        id: `${line.id}-${line.warning.code}`,
                        label: line.warning.label,
                        remarks: line.remarks,
                        from: line.from_date,
                        to: line.to_date,
                        isBlocking: line.warning.is_blocking,
                    });
                }

                if (
                    line.pay_category === 'excluded' &&
                    positiveDayCount(line.days) > 0
                ) {
                    excluded.push({
                        id: `excluded-${line.id}`,
                        lineId: line.id,
                        title: payrollPhaseTitle(phase, line),
                        from: line.from_date,
                        to: line.to_date,
                        days: line.days,
                    });

                    continue;
                }

                if (!isPayableCategory(line.pay_category)) {
                    continue;
                }

                if (positiveDayCount(line.days) <= 0) {
                    continue;
                }

                const dividerKey = `${line.pay_category}:${assignment.id ?? 'none'}`;
                const showDivider =
                    linkFromPrevious !== null &&
                    !dividerShownFor.has(dividerKey);

                if (showDivider) {
                    dividerShownFor.add(dividerKey);
                }

                segmentsByCategory[line.pay_category].push({
                    id: `line-${line.id}`,
                    lineId: line.id,
                    payCategory: line.pay_category,
                    title: payrollPhaseTitle(phase, line),
                    assignmentId: assignment.id,
                    assignmentNumber: assignment.assignment_number,
                    vessel: assignment.vessel,
                    rank: assignment.rank,
                    client: assignment.client,
                    actualStart: phase.actual_start ?? line.source_actual_start,
                    actualEnd: phase.actual_end ?? line.source_actual_end,
                    payrollFrom: line.from_date,
                    payrollTo: line.to_date,
                    days: line.days,
                    warnings: warningsForLine(phase, line),
                    linkFromPrevious: showDivider ? linkFromPrevious : null,
                });
            }
        }
    }

    for (const line of employee.lines ?? []) {
        if (!line.warning) {
            continue;
        }

        pushWarning({
            id: `${line.id}-${line.warning.code}`,
            label: line.warning.label,
            remarks: line.remarks,
            from: line.from_date,
            to: line.to_date,
            isBlocking: line.warning.is_blocking,
        });
    }

    warnings.sort((left, right) => {
        if (left.isBlocking !== right.isBlocking) {
            return left.isBlocking ? -1 : 1;
        }

        return (left.from ?? '').localeCompare(right.from ?? '');
    });

    return {
        categories: PAYABLE_CATEGORY_ORDER.map((category) => ({
            key: category.key,
            label: category.label,
            days: categoryDays(employee, category.key),
            segments: segmentsByCategory[category.key],
        })).filter(
            (category) => category.segments.length > 0 || category.days > 0,
        ),
        excluded,
        warnings,
        detectedPayableDays: employee.total_payable_days,
        appliedFromCrewOperationsDays: employee.is_skipped
            ? 0
            : employee.total_payable_days,
    };
}
