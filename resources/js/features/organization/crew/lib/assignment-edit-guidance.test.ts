import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildAssignmentEditGuidance } from './assignment-edit-guidance.ts';

const baseAssignment = {
    id: 42,
    assignment_no: 'CA-2026-000042',
    status: 'active',
    status_label: 'Active',
    is_editable: true,
    employee: {
        id: 10,
        name: 'Abdul Hamid',
        employee_no: '2073',
        image: null,
    },
    rank: { id: 1, name: 'Able Seaman' },
    vessel: { id: 5, name: 'Sea Eagle' },
    client: { id: 1, name: 'Client A' },
    current_phase: {
        id: 99,
        code: 'p2a',
        label: 'Join Standby',
        status: 'active',
        status_label: 'Active',
    },
    days_in_phase: 3,
    days_in_training: null,
    planned_join_at: '2026-10-05',
    planned_arrival_at: '2026-10-03',
    planned_signoff_at: '2026-11-08',
    planned_travel_at: null,
    actual_arrival_at: null,
    actual_join_at: null,
    actual_disembarkation_at: null,
    started_at: '2026-10-01',
    closed_at: null,
    source: 'manual',
    remarks: null,
    created_at: null,
    updated_at: null,
    phase_timeline: [],
    warnings: [],
    available_actions: [],
    mobilisation_readiness: null,
    recommended_action: null,
    planning_assignment_id: null,
    relieves: null,
    previous_assignment: null,
    next_assignments: [],
    movement_context: {
        can_perform_movement: true,
        can_request_correction: true,
    },
};

const vessels = [
    { id: 5, name: 'Sea Eagle' },
    { id: 8, name: 'Sea Falcon' },
];

const ranks = [
    { id: 1, name: 'Able Seaman' },
    { id: 2, name: 'Chief Officer' },
];

const permissions = {
    perform_movement: true,
    view_planning: true,
};

describe('buildAssignmentEditGuidance', () => {
    it('describes P2A editing guidance', () => {
        const guidance = buildAssignmentEditGuidance({
            assignment: baseAssignment,
            formData: {
                employee_id: 10,
                rank_id: 1,
                client_id: 1,
                vessel_id: 5,
                planned_join_at: '2026-10-05',
                planned_arrival_at: '2026-10-03',
                remarks: '',
            },
            vessels,
            ranks,
            permissions,
        });

        assert.match(guidance.phaseLabel, /P2A · Join Standby/);
        assert.match(guidance.explanation, /Waiting to join the vessel/);
        assert.ok(guidance.safeToUpdate?.includes('Vessel'));
    });

    it('shows destination update guidance for P2A vessel changes', () => {
        const guidance = buildAssignmentEditGuidance({
            assignment: baseAssignment,
            formData: {
                employee_id: 10,
                rank_id: 1,
                client_id: 1,
                vessel_id: 8,
                planned_join_at: '2026-10-05',
                planned_arrival_at: '2026-10-03',
                remarks: '',
            },
            vessels,
            ranks,
            permissions,
        });

        assert.equal(guidance.planningChanges.length, 1);
        assert.equal(guidance.planningChanges[0]?.field, 'vessel');
        assert.match(guidance.destinationNote ?? '', /not a Vessel Transfer/i);
    });

    it('detects vessel, rank, and expected join planning changes', () => {
        const guidance = buildAssignmentEditGuidance({
            assignment: baseAssignment,
            formData: {
                employee_id: 10,
                rank_id: 2,
                client_id: 1,
                vessel_id: 8,
                planned_join_at: '2026-10-08',
                planned_arrival_at: '2026-10-03',
                remarks: '',
            },
            vessels,
            ranks,
            permissions,
        });

        assert.equal(guidance.planningChanges.length, 3);
        assert.match(
            guidance.planningSyncNote ?? '',
            /not updated automatically/i,
        );
    });

    it('warns when arrival is after expected join', () => {
        const guidance = buildAssignmentEditGuidance({
            assignment: baseAssignment,
            formData: {
                employee_id: 10,
                rank_id: 1,
                client_id: 1,
                vessel_id: 5,
                planned_join_at: '2026-10-05',
                planned_arrival_at: '2026-10-09',
                remarks: '',
            },
            vessels,
            ranks,
            permissions,
        });

        assert.ok(
            guidance.dateAdvisories.some((advisory) =>
                advisory.message.includes(
                    'Arrival Date cannot be after Expected Vessel Join',
                ),
            ),
        );
    });

    it('warns when expected join is after planned sign-off', () => {
        const guidance = buildAssignmentEditGuidance({
            assignment: baseAssignment,
            formData: {
                employee_id: 10,
                rank_id: 1,
                client_id: 1,
                vessel_id: 5,
                planned_join_at: '2026-11-09',
                planned_arrival_at: '2026-10-03',
                remarks: '',
            },
            vessels,
            ranks,
            permissions,
        });

        assert.ok(
            guidance.dateAdvisories.some((advisory) =>
                advisory.message.includes('Planned Sign-Off'),
            ),
        );
    });
});
