import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { buildAssignmentEditGuidance } from './assignment-edit-guidance.ts';

describe('Crew assignment edit parity context', () => {
    it('explains pre-boarding vessel changes are not a transfer', () => {
        const guidance = buildAssignmentEditGuidance({
            assignment: {
                id: 1,
                assignment_no: 'CA-2026-000001',
                status: 'active',
                status_label: 'Active',
                is_editable: true,
                employee: {
                    id: 10,
                    name: 'Ahmed',
                    employee_no: '1001',
                    image: null,
                },
                rank: { id: 1, name: 'AB' },
                vessel: { id: 5, name: 'Vessel A' },
                client: null,
                current_phase: {
                    id: 1,
                    code: 'p2a',
                    label: 'Join Standby',
                    status: 'active',
                    status_label: 'Active',
                },
                days_in_phase: 2,
                days_in_training: null,
                planned_join_at: '2026-10-15',
                planned_arrival_at: '2026-10-10',
                planned_signoff_at: null,
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
            },
            formData: {
                employee_id: 10,
                rank_id: 1,
                client_id: null,
                vessel_id: 6,
                planned_join_at: '2026-10-15',
                planned_arrival_at: '2026-10-10',
                remarks: '',
            },
            vessels: [
                { id: 5, name: 'Vessel A' },
                { id: 6, name: 'Vessel B' },
            ],
            ranks: [{ id: 1, name: 'AB' }],
            permissions: {
                perform_movement: true,
                view_planning: true,
            },
        });

        assert.match(guidance.destinationNote ?? '', /not a Vessel Transfer/i);
        assert.equal(guidance.planningChanges.length, 1);
        assert.equal(guidance.planningChanges[0]?.field, 'vessel');
    });
});
