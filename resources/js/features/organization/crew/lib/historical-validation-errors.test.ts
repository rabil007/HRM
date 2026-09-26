import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { mapHistoricalValidationErrors } from './historical-validation-errors.ts';

describe('mapHistoricalValidationErrors', () => {
    it('aliases legacy event keys onto simplified period fields', () => {
        const result = mapHistoricalValidationErrors({
            training_start_at: ['Training start invalid'],
            demob_standby_at: ['Demob invalid'],
            mobilisation_start_at: ['Mobilisation invalid'],
            joined_vessel_at: ['Onsite from invalid'],
        });

        assert.equal(
            result.fieldErrors.sign_on_standby_from,
            'Training start invalid',
        );
        assert.equal(result.fieldErrors.sign_off_standby_from, 'Demob invalid');
        assert.equal(result.fieldErrors.onsite_from, 'Onsite from invalid');
        assert.equal(result.alertMessage, null);
    });

    it('surfaces domain overlap, ambiguous state, and sea service messages', () => {
        const result = mapHistoricalValidationErrors({
            overlap: ['Overlaps CA-2024-000031'],
            dates: [
                'All entered movement periods are closed. Enter Home / Available From, or leave the employee’s current movement period open.',
            ],
            sea_service: [
                'Matches existing Sea Service record #52 with conflicting rank.',
            ],
            employee_id: ['Employee not found'],
        });

        assert.match(result.alertMessage ?? '', /Overlaps CA-2024-000031/);
        assert.match(result.alertMessage ?? '', /All entered movement periods/);
        assert.match(result.alertMessage ?? '', /Sea Service record #52/);
        assert.equal(result.fieldErrors.employee_id, 'Employee not found');
    });
});
