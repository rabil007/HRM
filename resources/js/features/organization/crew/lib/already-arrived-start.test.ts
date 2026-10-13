import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    ACTUAL_ARRIVAL_DATE_HELPER,
    ACTUAL_ARRIVAL_DATE_HELPER_AFTER_PLANNING,
    ACTUAL_ARRIVAL_DATE_LABEL,
    ACTUAL_ARRIVAL_FUTURE_MESSAGE,
    ACTUAL_ARRIVAL_REQUIRED_MESSAGE,
    ALREADY_ARRIVED_EXPLANATION,
    ALREADY_ARRIVED_EXPLANATION_AFTER_PLANNING,
    ALREADY_ARRIVED_LABEL,
    ARRIVAL_DATE_OPTIONAL_LABEL,
    actualArrivalDateHelper,
    alreadyArrivedExplanation,
    normalizeArrivalDate,
    resolveArrivalAfterAlreadyArrivedToggle,
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

    it('uses planning-aware helper copy when a Crew Planning arrival was prefilled', () => {
        assert.equal(
            alreadyArrivedExplanation(true),
            ALREADY_ARRIVED_EXPLANATION_AFTER_PLANNING,
        );
        assert.equal(
            alreadyArrivedExplanation(false),
            ALREADY_ARRIVED_EXPLANATION,
        );
        assert.equal(
            actualArrivalDateHelper(true),
            ACTUAL_ARRIVAL_DATE_HELPER_AFTER_PLANNING,
        );
        assert.equal(
            actualArrivalDateHelper(false),
            ACTUAL_ARRIVAL_DATE_HELPER,
        );
        assert.match(
            ALREADY_ARRIVED_EXPLANATION_AFTER_PLANNING,
            /planned arrival from Crew Planning/,
        );
        assert.match(
            ACTUAL_ARRIVAL_DATE_HELPER_AFTER_PLANNING,
            /not reused as the actual date/,
        );
    });
});

describe('resolveArrivalAfterAlreadyArrivedToggle', () => {
    it('clears a planning-prefilled arrival when Already Arrived is enabled', () => {
        assert.equal(
            resolveArrivalAfterAlreadyArrivedToggle({
                enabling: true,
                currentArrival: '2026-10-02',
                planningPrefillArrival: '2026-10-02',
            }),
            null,
        );
        assert.equal(
            resolveArrivalAfterAlreadyArrivedToggle({
                enabling: true,
                currentArrival: '2026-10-02T00:00:00Z',
                planningPrefillArrival: '2026-10-02',
            }),
            null,
        );
    });

    it('keeps an operator-edited arrival when enabling Already Arrived', () => {
        assert.equal(
            resolveArrivalAfterAlreadyArrivedToggle({
                enabling: true,
                currentArrival: '2026-10-04',
                planningPrefillArrival: '2026-10-02',
            }),
            '2026-10-04',
        );
    });

    it('does not clear a manually entered date when there is no planning prefill', () => {
        assert.equal(
            resolveArrivalAfterAlreadyArrivedToggle({
                enabling: true,
                currentArrival: '2026-10-04',
                planningPrefillArrival: null,
            }),
            '2026-10-04',
        );
        assert.equal(
            resolveArrivalAfterAlreadyArrivedToggle({
                enabling: true,
                currentArrival: '2026-10-04',
                planningPrefillArrival: '',
            }),
            '2026-10-04',
        );
    });

    it('restores the planning prefill when disabling Already Arrived on an empty field', () => {
        assert.equal(
            resolveArrivalAfterAlreadyArrivedToggle({
                enabling: false,
                currentArrival: null,
                planningPrefillArrival: '2026-10-02',
            }),
            '2026-10-02',
        );
        assert.equal(
            resolveArrivalAfterAlreadyArrivedToggle({
                enabling: false,
                currentArrival: '',
                planningPrefillArrival: '2026-10-02',
            }),
            '2026-10-02',
        );
    });

    it('keeps an entered actual date when disabling Already Arrived', () => {
        assert.equal(
            resolveArrivalAfterAlreadyArrivedToggle({
                enabling: false,
                currentArrival: '2026-10-04',
                planningPrefillArrival: '2026-10-02',
            }),
            '2026-10-04',
        );
    });

    it('normalizes arrival dates to YYYY-MM-DD', () => {
        assert.equal(
            normalizeArrivalDate('2026-10-02T12:00:00Z'),
            '2026-10-02',
        );
        assert.equal(normalizeArrivalDate(null), '');
        assert.equal(normalizeArrivalDate(undefined), '');
    });
});
