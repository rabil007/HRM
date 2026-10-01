import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    createRequirementFormSnapshot,
    firstInvalidRequirementField,
    isRequirementFormDirty,
} from './requirement-form.ts';

describe('requirement form helpers', () => {
    it('detects dirty form state against a baseline snapshot', () => {
        const baseline = createRequirementFormSnapshot({
            client_id: '1',
            project_id: '',
            client_reference_number: '',
            location: '',
            assigned_to: '',
            request_received_date: '2026-10-01',
            required_by_date: '2026-10-15',
            priority: 'normal',
            notes: '',
            positions: [{ position_id: '4', required_headcount: 2 }],
            attachment: null,
        });

        assert.equal(isRequirementFormDirty(baseline, baseline), false);
        assert.equal(
            isRequirementFormDirty(
                baseline,
                createRequirementFormSnapshot({
                    client_id: baseline.client_id,
                    project_id: baseline.project_id,
                    client_reference_number: baseline.client_reference_number,
                    location: baseline.location,
                    assigned_to: baseline.assigned_to,
                    request_received_date: baseline.request_received_date,
                    required_by_date: baseline.required_by_date,
                    priority: baseline.priority,
                    notes: 'Updated',
                    positions: [{ position_id: '4', required_headcount: 2 }],
                    attachment: null,
                }),
            ),
            true,
        );
    });

    it('returns the first invalid field in display order', () => {
        assert.equal(
            firstInvalidRequirementField({
                notes: 'Too long',
                client_id: 'Required',
                'positions.0.position_id': 'Required',
            }),
            'client_id',
        );
        assert.equal(
            firstInvalidRequirementField({
                'positions.0.required_headcount': 'Min 1',
            }),
            'positions',
        );
        assert.equal(firstInvalidRequirementField({}), null);
    });
});
