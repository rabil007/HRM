import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { CrewAssignmentListItem } from '../types.ts';
import { crewAssignmentMobileCardModel } from './crew-assignment-mobile-card.ts';

function assignment(
    overrides: Partial<CrewAssignmentListItem> = {},
): CrewAssignmentListItem {
    return {
        id: 482,
        assignment_no: 'CA-00482',
        status: 'active',
        status_label: 'Active',
        is_editable: true,
        employee: {
            id: 12,
            name: 'Mohammed Rabil',
            employee_no: 'EMP-0012',
        },
        rank: { id: 1, name: 'Engineer' },
        vessel: { id: 8, name: 'Horizon' },
        client: null,
        company_visa_type: null,
        current_phase: {
            code: 'p4',
            label: 'Onboard',
            status: 'active',
        },
        days_in_phase: 12,
        planned_join_at: '2026-08-01',
        planned_signoff_at: '2026-08-28',
        actual_join_at: '2026-08-01',
        created_at: '2026-07-01',
        warnings: [],
        available_actions: ['confirm_disembarkation'],
        movement_context: {
            assignment_id: 482,
            assignment_no: 'CA-00482',
            employee_id: 12,
            employee_name: 'Mohammed Rabil',
            employee_no: 'EMP-0012',
            current_phase_code: 'p4',
            current_phase_label: 'Onboard',
            current_phase_started_at: '2026-08-01',
            days_in_phase: 12,
            days_onboard: 12,
            days_in_training: null,
            vessel_id: 8,
            vessel_name: 'Horizon',
            rank_id: 1,
            rank_name: 'Engineer',
            client_id: null,
            client_name: null,
            visa_type_id: null,
            visa_type_name: null,
            planned_join_at: '2026-08-01',
            planned_signoff_at: '2026-08-28',
            planned_travel_at: null,
            actual_join_at: '2026-08-01',
            actual_disembarkation_at: '2026-09-01',
            training_provider: null,
            training_course: null,
            training_started_at: null,
            training_expected_completion_at: null,
            company_timezone: 'Asia/Dubai',
            tour_of_duty_days: 28,
            planned_signoff_source: 'tour',
            planned_signoff_source_label: 'Tour',
            current_duty_day: 12,
            remaining_tour_days: 16,
            tour_progress_percent: 40,
            tour_progress_display_percent: 40,
            tour_status: 'on_track',
            tour_status_label: 'On track',
            tour_status_severity: 'info',
        },
        tour_of_duty_days: 28,
        planned_signoff_source: 'tour',
        planned_signoff_source_label: 'Tour',
        days_onboard: 12,
        current_duty_day: 12,
        remaining_tour_days: 16,
        tour_progress_percent: 40,
        tour_progress_display_percent: 40,
        tour_status: 'on_track',
        tour_status_label: 'On track',
        tour_status_severity: 'info',
        relief_status: 'no_relief',
        relief_status_label: 'No relief',
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
        days_until_signoff: 10,
        ...overrides,
    } as CrewAssignmentListItem;
}

describe('crewAssignmentMobileCardModel', () => {
    it('uses the current CrewAssignment phase, not a reconstructed state', () => {
        const model = crewAssignmentMobileCardModel(assignment(), {
            update: true,
            performMovement: true,
            cancel: true,
        });

        assert.equal(model.title, 'Mohammed Rabil');
        assert.equal(model.subtitle, 'CA-00482');
        assert.equal(model.vesselName, 'Horizon');
        assert.equal(model.phaseCode, 'p4');
        assert.equal(model.phaseLabel, 'Onboard');
        assert.equal(model.isOnVessel, true);
        assert.equal(model.plannedSignoffAt, '2026-08-28');
    });

    it('does not conflate planned sign-off with actual disembarkation', () => {
        const model = crewAssignmentMobileCardModel(
            assignment({
                planned_signoff_at: '2026-08-28',
                movement_context: {
                    ...assignment().movement_context,
                    planned_signoff_at: '2026-08-28',
                    actual_disembarkation_at: '2026-09-01',
                },
            }),
            { update: false, performMovement: false, cancel: false },
        );

        assert.equal(model.plannedSignoffAt, '2026-08-28');
        assert.equal(model.actualDisembarkationAt, null);
        assert.equal(JSON.stringify(model).includes('2026-09-01'), false);
        assert.equal(JSON.stringify(model).includes('01-09-2026'), false);
    });

    it('gates edit and movement actions by permission', () => {
        const viewOnly = crewAssignmentMobileCardModel(assignment(), {
            update: false,
            performMovement: false,
            cancel: false,
        });
        const mover = crewAssignmentMobileCardModel(assignment(), {
            update: true,
            performMovement: true,
            cancel: false,
        });

        assert.equal(viewOnly.showEdit, false);
        assert.equal(viewOnly.showMovement, false);
        assert.equal(mover.showEdit, true);
        assert.equal(mover.showMovement, true);
    });
});
