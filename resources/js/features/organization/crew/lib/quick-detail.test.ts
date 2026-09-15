import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewAssignmentListItem, CrewMovementContext } from '../types.ts';
import {
    calendarDayDifference,
    companyToday,
    crewQuickDetailModel,
    relativePlanDate,
} from './quick-detail.ts';

const can = {
    perform_movement: true,
    cancel: false,
    view_documents: true,
    view_planning: true,
};
const now = new Date('2026-09-14T10:00:00Z');

function listItem(
    overrides: Partial<CrewAssignmentListItem> = {},
): CrewAssignmentListItem {
    const tour = {
        tour_of_duty_days: null,
        planned_signoff_source: null,
        planned_signoff_source_label: null,
        days_onboard: null,
        current_duty_day: null,
        remaining_tour_days: null,
        tour_progress_percent: null,
        tour_progress_display_percent: null,
        tour_status: null,
        tour_status_label: null,
        tour_status_severity: null,
    };
    const movement: CrewMovementContext = {
        ...tour,
        assignment_id: 1,
        assignment_no: 'CA-001',
        employee_id: 1,
        employee_name: 'Crew Member',
        employee_no: 'E1',
        current_phase_code: 'p4',
        current_phase_label: 'On Vessel',
        current_phase_started_at: '2026-08-01',
        days_in_phase: 44,
        days_in_training: null,
        vessel_id: 1,
        vessel_name: 'Horizon',
        rank_id: null,
        rank_name: null,
        client_id: null,
        client_name: null,
        planned_join_at: '2026-08-01',
        planned_signoff_at: '2026-09-20',
        planned_travel_at: null,
        actual_join_at: '2026-08-01',
        actual_disembarkation_at: null,
        training_provider: null,
        training_course: null,
        training_started_at: null,
        training_expected_completion_at: null,
        company_timezone: 'Asia/Dubai',
    };

    return {
        ...tour,
        id: 1,
        assignment_no: 'CA-001',
        status: 'active',
        status_label: 'Active',
        is_editable: false,
        employee: { id: 1, name: 'Crew Member', employee_no: 'E1' },
        rank: null,
        vessel: { id: 1, name: 'Horizon' },
        client: null,
        current_phase: { code: 'p4', label: 'On Vessel', status: 'active' },
        days_in_phase: 44,
        planned_join_at: '2026-08-01',
        planned_signoff_at: '2026-09-20',
        created_at: '2026-08-01',
        warnings: [],
        available_actions: [
            'plan_signoff',
            'confirm_disembarkation',
            'cancel_assignment',
        ],
        mobilisation_readiness: null,
        movement_context: movement,
        relief_status: 'no_relief',
        relief_status_label: 'No Relief',
        relief_action_label: 'Plan Relief',
        relief_risk: null,
        relief_risk_label: null,
        relief_employee: null,
        relief_planning_assignment_id: null,
        relief_crew_assignment_id: null,
        relief_planned_join_date: null,
        relief_phase: null,
        relief_phase_code: null,
        relief_phase_label: null,
        relief_phase_status: null,
        source_planned_signoff_date: null,
        days_until_signoff: 6,
        ...overrides,
    };
}

describe('crew quick detail operational summary', () => {
    it('works with the actual lightweight list shape without a timeline or detail fields', () => {
        const assignment = listItem();
        assert.equal('phase_timeline' in assignment, false);
        const model = crewQuickDetailModel(assignment, can, now);
        assert.equal(model.milestone?.label, 'Planned sign-off');
        assert.equal(model.daysUntilMilestone, 6);
        assert.equal(model.needsRelief, true);
        assert.equal(model.movement, 'plan_signoff');
    });

    it('keeps overdue sign-off separate from the past planned join date', () => {
        const model = crewQuickDetailModel(
            listItem({ planned_signoff_at: '2026-09-12' }),
            can,
            now,
        );
        assert.equal(model.daysUntilMilestone, -2);
        assert.equal(relativePlanDate(model.daysUntilMilestone), '2d overdue');
    });

    it('uses training completion for crew in training', () => {
        const assignment = listItem();
        const model = crewQuickDetailModel(
            {
                ...assignment,
                current_phase: {
                    code: 'p2b',
                    label: 'Training',
                    status: 'active',
                },
                movement_context: {
                    ...assignment.movement_context,
                    training_expected_completion_at: '2026-09-15',
                },
                available_actions: ['complete_training'],
            },
            can,
            now,
        );
        assert.deepEqual(model.milestone, {
            label: 'Training completion',
            date: '2026-09-15',
        });
        assert.equal(model.movement, 'complete_training');
    });

    it('shows travel first for drafts and joining for travelling crew', () => {
        const assignment = listItem({
            planned_travel_at: '2026-09-15',
            planned_join_at: '2026-09-17',
        });
        const draft = crewQuickDetailModel(
            {
                ...assignment,
                current_phase: {
                    code: 'p0',
                    label: 'Draft',
                    status: 'planned',
                },
            },
            can,
            now,
        );
        const travelling = crewQuickDetailModel(
            {
                ...assignment,
                current_phase: {
                    code: 'p1',
                    label: 'Travel In',
                    status: 'active',
                },
            },
            can,
            now,
        );
        assert.equal(draft.milestone?.date, '2026-09-15');
        assert.equal(travelling.milestone?.date, '2026-09-17');
    });

    it('does not show historic joining as the next event during demobilisation or after closure', () => {
        for (const phase of ['p5', 'p6']) {
            const model = crewQuickDetailModel(
                listItem({
                    current_phase: {
                        code: phase,
                        label: 'Demobilisation',
                        status: 'active',
                    },
                }),
                can,
                now,
            );
            assert.equal(model.milestone, null);
        }

        for (const status of ['closed', 'cancelled', 'voided']) {
            const model = crewQuickDetailModel(listItem({ status }), can, now);
            assert.equal(model.milestone, null);
            assert.equal(model.movement, null);
            assert.equal(model.needsRelief, false);
        }
    });

    it('separately gates movement and cancellation and never suggests unavailable movements', () => {
        const viewer = crewQuickDetailModel(
            listItem(),
            { ...can, perform_movement: false },
            now,
        );
        assert.deepEqual(viewer.availableActions, []);
        assert.equal(viewer.movement, null);
        const canceller = crewQuickDetailModel(
            listItem(),
            { ...can, perform_movement: false, cancel: true },
            now,
        );
        assert.deepEqual(canceller.availableActions, ['cancel_assignment']);
        assert.equal(canceller.movement, null);
        const mover = crewQuickDetailModel(listItem(), can, now);
        assert.equal(
            mover.availableActions.includes('cancel_assignment'),
            false,
        );
        assert.equal(
            crewQuickDetailModel(
                listItem({ available_actions: ['transfer_vessel'] }),
                can,
                now,
            ).movement,
            null,
        );
    });

    it('prioritizes critical document issues without blocking permitted movements', () => {
        const model = crewQuickDetailModel(
            listItem({
                current_phase: {
                    code: 'p0',
                    label: 'Pre-Mobilisation',
                    status: 'planned',
                },
                available_actions: ['approve_mobilisation'],
                warnings: [
                    {
                        code: 'late',
                        severity: 'warning',
                        label: 'Late',
                        message: 'Review planned join.',
                        date: null,
                    },
                ],
                mobilisation_readiness: {
                    applies: true,
                    status: 'not_ready',
                    status_label: 'Not Ready',
                    checks_clear: 2,
                    checks_total: 3,
                    advisory_note: 'Advisory only',
                    documents_href: null,
                    problems: [
                        {
                            code: 'missing',
                            severity: 'critical',
                            label: 'Missing certificate',
                            message: 'Required certificate is missing.',
                            document_type_id: 1,
                        },
                    ],
                },
            }),
            can,
            now,
        );
        assert.equal(model.issues[0].source, 'Documents');
        assert.equal(model.issues[1].source, 'Movement');
        assert.equal(model.needsDocumentReview, true);
        assert.equal(model.movement, 'approve_mobilisation');
    });

    it('handles omitted collections defensively and does not infer document clearance from no checks', () => {
        const model = crewQuickDetailModel(
            listItem({
                warnings: undefined,
                available_actions: undefined,
                mobilisation_readiness: {
                    applies: true,
                    status: 'ready',
                    status_label: 'No Checks Configured',
                    checks_clear: 0,
                    checks_total: 0,
                    advisory_note: '',
                    documents_href: null,
                    problems: [],
                },
            }),
            can,
            now,
        );
        assert.deepEqual(model.issues, []);
        assert.deepEqual(model.availableActions, []);
        assert.equal(model.needsDocumentReview, false);
        assert.equal(model.readiness?.checks_total, 0);
    });

    it('calculates relief timing from planned dates without implying actual coverage', () => {
        assert.equal(
            crewQuickDetailModel(
                listItem({ relief_planned_join_date: '2026-09-23' }),
                can,
                now,
            ).reliefGapDays,
            3,
        );
        assert.equal(
            crewQuickDetailModel(
                listItem({ relief_planned_join_date: '2026-09-18' }),
                can,
                now,
            ).reliefGapDays,
            -2,
        );
        assert.equal(
            crewQuickDetailModel(
                listItem({ planned_signoff_at: null }),
                can,
                now,
            ).reliefGapDays,
            null,
        );
    });

    it('uses company-local calendar days across midnight and DST changes', () => {
        const midnight = new Date('2026-09-14T22:30:00Z');
        assert.equal(companyToday(midnight, 'Asia/Dubai'), '2026-09-15');
        const model = crewQuickDetailModel(
            listItem({ planned_signoff_at: '2026-09-15' }),
            can,
            midnight,
        );
        assert.equal(model.daysUntilMilestone, 0);
        assert.equal(relativePlanDate(model.daysUntilMilestone), 'Today');
        assert.equal(calendarDayDifference('2026-03-09', '2026-03-08'), 1);
        assert.equal(calendarDayDifference(null, '2026-09-14'), null);
    });
});
