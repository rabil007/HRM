import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    resolveCrewMemberArrivalPresentation,
    shouldShowSingleCreatePreJoinAccommodation,
} from './crew-assignment-create-arrival-field.ts';

describe('shouldShowSingleCreatePreJoinAccommodation', () => {
    it('shows pre-join on single create with movement permission', () => {
        assert.equal(
            shouldShowSingleCreatePreJoinAccommodation({
                useDirectCreateActions: true,
                canStart: true,
                bulkMode: false,
            }),
            true,
        );
    });

    it('hides pre-join in bulk mode', () => {
        assert.equal(
            shouldShowSingleCreatePreJoinAccommodation({
                useDirectCreateActions: true,
                canStart: true,
                bulkMode: true,
            }),
            false,
        );
    });
});

describe('resolveCrewMemberArrivalPresentation', () => {
    it('keeps optional arrival labeling on single create while exposing pre-join fields', () => {
        assert.deepEqual(
            resolveCrewMemberArrivalPresentation({
                bulkAlreadyArrived: false,
                showSingleCreatePreJoin: true,
            }),
            {
                useActualArrivalLabel: false,
                showPreJoinAccommodation: true,
                showAlreadyArrivedFooterNote: true,
            },
        );
    });

    it('uses actual arrival labeling only for bulk already arrived', () => {
        assert.deepEqual(
            resolveCrewMemberArrivalPresentation({
                bulkAlreadyArrived: true,
                showSingleCreatePreJoin: false,
            }),
            {
                useActualArrivalLabel: true,
                showPreJoinAccommodation: true,
                showAlreadyArrivedFooterNote: false,
            },
        );
    });
});
