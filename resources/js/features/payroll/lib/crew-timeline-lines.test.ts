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
    buildCrewTimelinePayrollBreakdown,
    formatAssignmentCountLabel,
    formatCrewTimelineDate,
    formatCrewTimelineDateRange,
    formatCrewTimelineDayCount,
    formatCrewTimelineDays,
    formatCrewTimelinePeriodLabel,
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
        is_skipped: false,
        skip_reason: null,
        skipped_by: null,
        skipped_at: null,
        can_skip: false,
        can_restore: false,
        has_cross_company_warning: false,
        blocking_warning_count: 0,
        unresolved_blocking_warning_count: 0,
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
        assert.equal(sections[1]?.linkFromPrevious?.fromAssignmentId, 1);
        assert.equal(sections[1]?.linkFromPrevious?.toAssignmentId, 2);
        assert.equal(
            sections[1]?.linkFromPrevious?.fromAssignmentNumber,
            'CA-1',
        );
        assert.equal(sections[1]?.linkFromPrevious?.toAssignmentNumber, 'CA-2');
        assert.equal(sections[1]?.linkFromPrevious?.fromVessel, 'Vessel A');
        assert.equal(sections[1]?.linkFromPrevious?.toVessel, 'Vessel B');
        assert.equal(sections[2]?.linkFromPrevious?.kind, 'redeployment');
        assert.equal(sections[2]?.linkFromPrevious?.label, 'Redeployment');
        assert.equal(sections[2]?.linkFromPrevious?.fromAssignmentId, 2);
        assert.equal(sections[2]?.linkFromPrevious?.toAssignmentId, 3);
    });

    it('uses employee summary day totals without recalculating category days', () => {
        const employee = employeeSummary({
            sign_on_standby_days: 5,
            onsite_days: 20,
            sign_off_standby_days: 2,
            total_payable_days: 27,
        });

        const breakdown = buildCrewTimelinePayrollBreakdown(employee);

        assert.equal(
            breakdown.categories.find(
                (category) => category.key === 'sign_on_standby',
            )?.days,
            5,
        );
        assert.equal(
            breakdown.categories.find((category) => category.key === 'onsite')
                ?.days,
            20,
        );
        assert.equal(
            breakdown.categories.find(
                (category) => category.key === 'sign_off_standby',
            )?.days,
            2,
        );
        assert.equal(breakdown.detectedPayableDays, 27);
        assert.equal(formatCrewTimelineDayCount(5), '5 days');
        assert.equal(formatCrewTimelineDayCount(1), '1 day');
    });

    it('keeps separate payable periods instead of collapsing a date gap', () => {
        const employee = employeeSummary({
            sign_on_standby_days: 8,
            onsite_days: 0,
            onsite_from: null,
            onsite_to: null,
            total_payable_days: 8,
            assignments: [
                assignmentSummary({
                    phases: [
                        phaseOccurrence({
                            phase_code: 'p2a',
                            phase_code_display: 'P2A',
                            phase_label: 'Join Standby',
                            actual_start: '2026-08-01',
                            actual_end: '2026-08-10',
                            payroll_from: '2026-08-01',
                            payroll_to: '2026-08-10',
                            payable_from: '2026-08-01',
                            payable_to: '2026-08-10',
                            payable_days: '8.00',
                            payroll_lines: [
                                timelineLine({
                                    id: 11,
                                    phase_code: 'p2a',
                                    phase_label: 'Join Standby',
                                    pay_category: 'sign_on_standby',
                                    pay_category_label: 'Sign-on Standby',
                                    from_date: '2026-08-01',
                                    to_date: '2026-08-04',
                                    days: '4.00',
                                    source_actual_start: '2026-08-01',
                                    source_actual_end: '2026-08-10',
                                }),
                                timelineLine({
                                    id: 12,
                                    phase_code: 'p2a',
                                    phase_label: 'Join Standby',
                                    pay_category: 'sign_on_standby',
                                    pay_category_label: 'Sign-on Standby',
                                    from_date: '2026-08-07',
                                    to_date: '2026-08-10',
                                    days: '4.00',
                                    source_actual_start: '2026-08-01',
                                    source_actual_end: '2026-08-10',
                                }),
                            ],
                        }),
                    ],
                }),
            ],
        });

        const signOn = buildCrewTimelinePayrollBreakdown(
            employee,
        ).categories.find((category) => category.key === 'sign_on_standby');

        assert.equal(signOn?.segments.length, 2);
        assert.deepEqual(
            signOn?.segments.map((segment) => [
                segment.payrollFrom,
                segment.payrollTo,
                segment.days,
            ]),
            [
                ['2026-08-01', '2026-08-04', '4.00'],
                ['2026-08-07', '2026-08-10', '4.00'],
            ],
        );
        assert.equal(signOn?.days, 8);
    });

    it('shows each assignment onsite period separately with a vessel transfer divider', () => {
        const employee = employeeSummary({
            onsite_days: 20,
            total_payable_days: 20,
            assignments: [
                assignmentSummary({
                    id: 41,
                    assignment_number: 'CA-2026-000041',
                    vessel: 'HEA KRAKEN',
                    phases: [
                        phaseOccurrence({
                            id: 21,
                            payroll_lines: [
                                timelineLine({
                                    id: 21,
                                    from_date: '2026-08-06',
                                    to_date: '2026-08-20',
                                    days: '15.00',
                                }),
                            ],
                            payable_days: '15.00',
                        }),
                    ],
                }),
                assignmentSummary({
                    id: 42,
                    assignment_number: 'CA-2026-000042',
                    source: 'vessel_transfer',
                    source_label: 'Vessel Transfer',
                    previous_assignment_id: 41,
                    previous_assignment_number: 'CA-2026-000041',
                    previous_vessel: 'HEA KRAKEN',
                    vessel: 'PLB 648',
                    phases: [
                        phaseOccurrence({
                            id: 22,
                            payroll_lines: [
                                timelineLine({
                                    id: 22,
                                    from_date: '2026-08-21',
                                    to_date: '2026-08-25',
                                    days: '5.00',
                                }),
                            ],
                            payable_days: '5.00',
                        }),
                    ],
                }),
            ],
        });

        const onsite = buildCrewTimelinePayrollBreakdown(
            employee,
        ).categories.find((category) => category.key === 'onsite');

        assert.equal(onsite?.segments.length, 2);
        assert.equal(onsite?.segments[0]?.assignmentNumber, 'CA-2026-000041');
        assert.equal(onsite?.segments[0]?.vessel, 'HEA KRAKEN');
        assert.equal(onsite?.segments[0]?.linkFromPrevious, null);
        assert.equal(onsite?.segments[1]?.assignmentNumber, 'CA-2026-000042');
        assert.equal(onsite?.segments[1]?.vessel, 'PLB 648');
        assert.equal(
            onsite?.segments[1]?.linkFromPrevious?.kind,
            'vessel_transfer',
        );
        assert.notEqual(
            `${onsite?.segments[0]?.payrollFrom}:${onsite?.segments[0]?.payrollTo}`,
            `${onsite?.segments[1]?.payrollFrom}:${onsite?.segments[1]?.payrollTo}`,
        );
    });

    it('keeps excluded periods out of payable categories', () => {
        const employee = employeeSummary({
            sign_on_standby_days: 0,
            onsite_days: 3,
            total_payable_days: 3,
            assignments: [
                assignmentSummary({
                    phases: [
                        phaseOccurrence({
                            id: 31,
                            phase_code: 'p1',
                            phase_code_display: 'P1',
                            phase_label: 'Travel In',
                            payroll_lines: [
                                timelineLine({
                                    id: 31,
                                    phase_code: 'p1',
                                    phase_label: 'Travel In',
                                    pay_category: 'excluded',
                                    pay_category_label: 'Excluded',
                                    from_date: '2026-08-01',
                                    to_date: '2026-08-02',
                                    days: '2.00',
                                }),
                            ],
                            primary_treatment: {
                                pay_category: 'excluded',
                                pay_category_label: 'Excluded',
                                from_date: '2026-08-01',
                                to_date: '2026-08-02',
                                days: '2.00',
                            },
                            payable_days: '0.00',
                        }),
                        phaseOccurrence(),
                    ],
                }),
            ],
        });

        const breakdown = buildCrewTimelinePayrollBreakdown(employee);
        const payableLineIds = breakdown.categories.flatMap((category) =>
            category.segments.map((segment) => segment.lineId),
        );

        assert.deepEqual(
            breakdown.excluded.map((segment) => segment.lineId),
            [31],
        );
        assert.equal(payableLineIds.includes(31), false);
        assert.equal(breakdown.detectedPayableDays, 3);
    });

    it('keeps blocking and informational warnings visible', () => {
        const employee = employeeSummary({
            blocking_warning_count: 1,
            informational_warning_count: 1,
            assignments: [
                assignmentSummary({
                    phases: [
                        phaseOccurrence({
                            warnings: [
                                {
                                    code: 'timeline_gap',
                                    label: 'Timeline Gap',
                                    is_blocking: false,
                                    remarks:
                                        '2 days have no payable Crew Operations phase.',
                                    from_date: '2026-08-24',
                                    to_date: '2026-08-25',
                                    line_id: 41,
                                },
                                {
                                    code: 'overlapping_phases',
                                    label: 'Overlapping Phases',
                                    is_blocking: true,
                                    remarks:
                                        'These operational phases genuinely overlap.',
                                    from_date: '2026-08-26',
                                    to_date: '2026-08-31',
                                    line_id: 42,
                                },
                            ],
                            payroll_lines: [
                                timelineLine(),
                                timelineLine({
                                    id: 41,
                                    days: '0.00',
                                    pay_category: null,
                                    warning: {
                                        code: 'timeline_gap',
                                        label: 'Timeline Gap',
                                        is_blocking: false,
                                    },
                                    remarks:
                                        '2 days have no payable Crew Operations phase.',
                                    from_date: '2026-08-24',
                                    to_date: '2026-08-25',
                                }),
                                timelineLine({
                                    id: 42,
                                    days: '0.00',
                                    pay_category: null,
                                    warning: {
                                        code: 'overlapping_phases',
                                        label: 'Overlapping Phases',
                                        is_blocking: true,
                                    },
                                    remarks:
                                        'These operational phases genuinely overlap.',
                                    from_date: '2026-08-26',
                                    to_date: '2026-08-31',
                                }),
                            ],
                        }),
                    ],
                }),
            ],
        });

        const warnings = buildCrewTimelinePayrollBreakdown(employee).warnings;

        assert.deepEqual(
            warnings.map((warning) => [warning.label, warning.isBlocking]),
            [
                ['Overlapping Phases', true],
                ['Timeline Gap', false],
            ],
        );
        assert.equal(
            warnings.find((warning) => !warning.isBlocking)?.remarks,
            '2 days have no payable Crew Operations phase.',
        );
    });

    it('distinguishes detected and applied days for skipped employees without hiding the original breakdown', () => {
        const employee = employeeSummary({
            total_payable_days: 27,
            sign_on_standby_days: 5,
            onsite_days: 20,
            sign_off_standby_days: 2,
            is_skipped: true,
            skip_reason: 'Movement dates require correction',
            skipped_by: { id: 9, name: 'Mohammed' },
            skipped_at: '2026-08-31T10:00:00+04:00',
        });

        const breakdown = buildCrewTimelinePayrollBreakdown(employee);

        assert.equal(breakdown.detectedPayableDays, 27);
        assert.equal(breakdown.appliedFromCrewOperationsDays, 0);
        assert.equal(
            breakdown.categories.find((category) => category.key === 'onsite')
                ?.segments.length,
            1,
        );
    });

    it('does not include planned schedule fields in the payroll breakdown', () => {
        const employee = employeeSummary({
            assignments: [
                assignmentSummary({
                    phases: [
                        phaseOccurrence({
                            planned_start: '2026-07-01',
                            planned_end: '2026-07-31',
                            planned_date_origin: 'crew_planning',
                            planned_date_origin_label: 'Crew Planning',
                            has_planned_schedule: true,
                        }),
                    ],
                }),
            ],
        });

        const serialized = JSON.stringify(
            buildCrewTimelinePayrollBreakdown(employee),
        );

        assert.equal(serialized.includes('planned_start'), false);
        assert.equal(serialized.includes('planned_end'), false);
        assert.equal(serialized.includes('has_planned_schedule'), false);
        assert.equal(serialized.includes('crew_planning'), false);
        assert.equal(serialized.includes('Crew Planning'), false);
        assert.equal(
            formatCrewTimelinePeriodLabel({
                name: 'August 2026 - Crew',
                start_date: '2026-08-01',
            }),
            'August 2026',
        );
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
