import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type {
    CrewTimelineAssignmentSummary,
    CrewTimelineEmployeeSummary,
    CrewTimelineLine,
    CrewTimelinePhaseOccurrence,
} from '../crew-timeline/types.ts';
import {
    buildCrewTimelineAssignmentSections,
    formatAssignmentCountLabel,
    formatCrewTimelineDate,
    formatCrewTimelineDateRange,
    formatCrewTimelineDays,
    phaseOccurrenceTitle,
    summarizeCrewTimelineEmployee,
    summarizeCrewTimelineLines,
} from './crew-timeline-lines.ts';

function timelineLine(
    overrides: Partial<CrewTimelineLine> = {},
): CrewTimelineLine {
    return {
        id: 1,
        phase_code: 'p4',
        phase_label: 'On Vessel',
        pay_category: 'onsite',
        pay_category_label: 'Onsite',
        from_date: '2026-08-08',
        to_date: '2026-08-10',
        days: '3.00',
        source_actual_start: '2026-08-08',
        source_actual_end: '2026-08-10',
        warning: null,
        remarks: null,
        ...overrides,
    };
}

function phaseOccurrence(
    overrides: Partial<CrewTimelinePhaseOccurrence> = {},
): CrewTimelinePhaseOccurrence {
    return {
        id: 10,
        phase_code: 'p4',
        phase_code_display: 'P4',
        phase_label: 'On Vessel',
        sequence: 1,
        status: 'completed',
        status_label: 'Completed',
        planned_start: null,
        planned_end: null,
        planned_date_origin: null,
        planned_date_origin_label: null,
        actual_start: '2026-08-08',
        actual_end: '2026-08-10',
        actual_date_origin: 'movement_actual',
        actual_date_origin_label: 'Derived from actual movement',
        payroll_from: '2026-08-08',
        payroll_to: '2026-08-10',
        payroll_date_origin: 'payroll_allocation',
        payroll_date_origin_label: 'Payroll allocation',
        payroll_period_label: 'Payroll allocation',
        payroll_lines: [timelineLine()],
        primary_treatment: {
            pay_category: 'onsite',
            pay_category_label: 'Onsite',
            from_date: '2026-08-08',
            to_date: '2026-08-10',
            days: '3.00',
        },
        excluded_treatment: null,
        payable_from: '2026-08-08',
        payable_to: '2026-08-10',
        payable_days: '3.00',
        is_operational: true,
        warnings: [],
        remarks: [],
        occurrence: null,
        occurrence_count: 1,
        has_planned_schedule: false,
        has_payroll_period: true,
        ...overrides,
    };
}

function assignmentSummary(
    overrides: Partial<CrewTimelineAssignmentSummary> = {},
): CrewTimelineAssignmentSummary {
    return {
        id: 1,
        assignment_number: 'CA-2026-000001',
        source: 'manual',
        source_label: 'Manual Assignment',
        status: 'active',
        status_label: 'Active',
        previous_assignment_id: null,
        previous_assignment_number: null,
        previous_vessel: null,
        vessel: 'ADNOC-A09',
        client: 'ADNOC',
        rank: 'Mechanical Technician',
        phases: [phaseOccurrence()],
        ...overrides,
    };
}

function employeeSummary(
    overrides: Partial<CrewTimelineEmployeeSummary> = {},
): CrewTimelineEmployeeSummary {
    const assignments = overrides.assignments ?? [assignmentSummary()];

    return {
        employee_id: 1,
        employee_number: '3007',
        employee_name: 'Test Employee',
        rank: 'Mechanical Technician',
        assignment_id:
            assignments.length === 1 ? (assignments[0]?.id ?? null) : null,
        assignment_number:
            assignments.length === 1
                ? (assignments[0]?.assignment_number ?? null)
                : null,
        vessel:
            assignments.length === 1 ? (assignments[0]?.vessel ?? null) : null,
        assignment_count: assignments.length,
        sign_on_standby_from: null,
        sign_on_standby_to: null,
        sign_on_standby_days: 0,
        onsite_from: '2026-08-08',
        onsite_to: '2026-08-10',
        onsite_days: 3,
        sign_off_standby_from: null,
        sign_off_standby_to: null,
        sign_off_standby_days: 0,
        total_payable_days: 3,
        blocking_warning_count: 0,
        informational_warning_count: 0,
        assignments,
        lines: assignments.flatMap((assignment) =>
            assignment.phases.flatMap((phase) => phase.payroll_lines),
        ),
        ...overrides,
    };
}

describe('crew timeline line presentation', () => {
    it('summarizes excluded and warning lines for the modal overview', () => {
        const summary = summarizeCrewTimelineLines([
            timelineLine(),
            timelineLine({
                id: 2,
                pay_category: 'excluded',
                warning: {
                    code: 'future_actual_date',
                    label: 'Future actual date',
                    is_blocking: false,
                },
            }),
            timelineLine({
                id: 3,
                warning: {
                    code: 'overlap',
                    label: 'Overlapping payable dates',
                    is_blocking: true,
                },
            }),
        ]);

        assert.deepEqual(summary, {
            lineCount: 3,
            excludedLineCount: 1,
            warningCount: 2,
            blockingWarningCount: 1,
        });
    });

    it('formats dates and same-day ranges without ambiguous numeric dates', () => {
        assert.equal(formatCrewTimelineDate('2026-08-04'), '04 Aug 2026');
        assert.equal(
            formatCrewTimelineDateRange('2026-08-04', '2026-08-04'),
            '04 Aug 2026',
        );
        assert.equal(
            formatCrewTimelineDateRange('2026-08-04', '2026-08-07'),
            '04 Aug 2026 – 07 Aug 2026',
        );
        assert.equal(
            formatCrewTimelineDateRange(null, null),
            'No planned dates',
        );
    });

    it('uses readable singular, plural, and fractional day labels', () => {
        assert.equal(formatCrewTimelineDays('1.00'), '1 day');
        assert.equal(formatCrewTimelineDays('3.00'), '3 days');
        assert.equal(formatCrewTimelineDays('0.50'), '0.5 days');
    });

    it('summarizes employee modal metrics without counting warning-only rows as phases', () => {
        const employee = employeeSummary({
            assignment_count: 2,
            total_payable_days: 9,
            blocking_warning_count: 1,
            informational_warning_count: 1,
            assignments: [
                assignmentSummary({
                    id: 1,
                    phases: [
                        phaseOccurrence({
                            id: 11,
                            payroll_lines: [
                                timelineLine({ id: 1, days: '6.00' }),
                                timelineLine({
                                    id: 2,
                                    days: '0.00',
                                    pay_category: 'excluded',
                                    warning: {
                                        code: 'future_actual_date',
                                        label: 'Future actual date',
                                        is_blocking: false,
                                    },
                                }),
                            ],
                            payable_days: '6.00',
                            warnings: [
                                {
                                    code: 'future_actual_date',
                                    label: 'Future actual date',
                                    is_blocking: false,
                                    remarks: null,
                                    from_date: null,
                                    to_date: null,
                                    line_id: 2,
                                },
                            ],
                        }),
                    ],
                }),
                assignmentSummary({
                    id: 2,
                    assignment_number: 'CA-2026-000002',
                    source: 'vessel_transfer',
                    source_label: 'Vessel Transfer',
                    previous_assignment_id: 1,
                    previous_assignment_number: 'CA-2026-000001',
                    vessel: 'ADNOC-A12',
                    phases: [
                        phaseOccurrence({
                            id: 12,
                            occurrence: 1,
                            occurrence_count: 1,
                            payroll_lines: [
                                timelineLine({ id: 3, days: '3.00' }),
                            ],
                            payable_days: '3.00',
                            is_operational: true,
                        }),
                        phaseOccurrence({
                            id: 13,
                            is_operational: false,
                            payroll_lines: [
                                timelineLine({
                                    id: 4,
                                    days: '0.00',
                                    warning: {
                                        code: 'missing_actual_start',
                                        label: 'Missing actual start',
                                        is_blocking: true,
                                    },
                                }),
                            ],
                            payable_days: '0.00',
                            primary_treatment: null,
                            warnings: [
                                {
                                    code: 'missing_actual_start',
                                    label: 'Missing actual start',
                                    is_blocking: true,
                                    remarks: null,
                                    from_date: null,
                                    to_date: null,
                                    line_id: 4,
                                },
                            ],
                        }),
                    ],
                }),
            ],
        });

        assert.deepEqual(summarizeCrewTimelineEmployee(employee), {
            assignmentCount: 2,
            operationalPhaseCount: 2,
            payablePeriodCount: 2,
            payableDays: 9,
            blockingWarningCount: 1,
            informationalWarningCount: 1,
        });
        assert.equal(
            formatAssignmentCountLabel(2),
            '2 assignments included in this payroll period',
        );
    });

    it('builds vessel transfer and redeployment dividers between linked assignments', () => {
        const sections = buildCrewTimelineAssignmentSections([
            assignmentSummary({
                id: 1,
                assignment_number: 'CA-1',
                vessel: 'Vessel A',
            }),
            assignmentSummary({
                id: 2,
                assignment_number: 'CA-2',
                source: 'vessel_transfer',
                source_label: 'Vessel Transfer',
                previous_assignment_id: 1,
                previous_assignment_number: 'CA-1',
                previous_vessel: 'Vessel A',
                vessel: 'Vessel B',
            }),
            assignmentSummary({
                id: 3,
                assignment_number: 'CA-3',
                source: 'redeployment',
                source_label: 'Redeployment',
                previous_assignment_id: 2,
                previous_assignment_number: 'CA-2',
                previous_vessel: 'Vessel B',
                vessel: 'Vessel C',
            }),
        ]);

        assert.equal(sections[0]?.linkFromPrevious, null);
        assert.equal(sections[1]?.linkFromPrevious?.kind, 'vessel_transfer');
        assert.equal(
            sections[1]?.linkFromPrevious?.fromAssignmentNumber,
            'CA-1',
        );
        assert.equal(sections[1]?.linkFromPrevious?.toAssignmentNumber, 'CA-2');
        assert.equal(sections[1]?.linkFromPrevious?.fromVessel, 'Vessel A');
        assert.equal(sections[1]?.linkFromPrevious?.toVessel, 'Vessel B');
        assert.equal(sections[2]?.linkFromPrevious?.kind, 'redeployment');
        assert.equal(sections[2]?.linkFromPrevious?.label, 'Redeployment');
    });

    it('labels repeated phase occurrences without generic entry numbers', () => {
        assert.equal(
            phaseOccurrenceTitle(
                phaseOccurrence({
                    occurrence: 2,
                    occurrence_count: 2,
                }),
            ),
            'P4 — On Vessel · Occurrence 2',
        );
        assert.equal(
            phaseOccurrenceTitle(phaseOccurrence({ occurrence: null })),
            'P4 — On Vessel',
        );
    });
});
