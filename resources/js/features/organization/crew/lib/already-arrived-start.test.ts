import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    ACTUAL_ARRIVAL_DATE_LABEL,
    ACTUAL_ARRIVAL_FUTURE_MESSAGE,
    ACTUAL_ARRIVAL_REQUIRED_MESSAGE,
    ALREADY_ARRIVED_EXPLANATION,
    ALREADY_ARRIVED_LABEL,
    ARRIVAL_DATE_OPTIONAL_LABEL,
} from './already-arrived-start.ts';

describe('already-arrived-start copy', () => {
    it('exposes the operator-facing checkbox label', () => {
        assert.equal(
            ALREADY_ARRIVED_LABEL,
            'Already Arrived — Skip Pre-Mobilisation',
        );
    });

    it('explains the P2A shortcut', () => {
        assert.match(ALREADY_ARRIVED_EXPLANATION, /Join Standby \(P2A\)/);
        assert.match(ALREADY_ARRIVED_EXPLANATION, /skipping Pre-Mobilisation/);
    });

    it('switches arrival labels for the checked state', () => {
        assert.equal(ARRIVAL_DATE_OPTIONAL_LABEL, 'Arrival Date');
        assert.equal(ACTUAL_ARRIVAL_DATE_LABEL, 'Actual Arrival Date');
    });

    it('keeps frontend validation messages aligned with the backend', () => {
        assert.equal(
            ACTUAL_ARRIVAL_REQUIRED_MESSAGE,
            'Actual Arrival Date is required when Already Arrived is selected.',
        );
        assert.equal(
            ACTUAL_ARRIVAL_FUTURE_MESSAGE,
            'Actual Arrival Date cannot be in the future.',
        );
    });
});
