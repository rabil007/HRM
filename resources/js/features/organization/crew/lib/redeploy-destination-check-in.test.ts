import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { resolveDestinationCheckInDateOnP2AEntry } from './redeploy-destination-check-in.ts';

describe('resolveDestinationCheckInDateOnP2AEntry', () => {
    it('sets check-in to the current redeploy date when empty', () => {
        assert.deepEqual(
            resolveDestinationCheckInDateOnP2AEntry({
                redeployDate: '2026-09-20',
                currentCheckInDate: '',
                lastAutoCheckInDate: '2026-09-17',
                noHotelAccommodation: false,
            }),
            {
                checkInDate: '2026-09-20',
                lastAutoCheckInDate: '2026-09-20',
            },
        );
    });

    it('updates an untouched auto-derived check-in date', () => {
        assert.deepEqual(
            resolveDestinationCheckInDateOnP2AEntry({
                redeployDate: '2026-09-20',
                currentCheckInDate: '2026-09-17',
                lastAutoCheckInDate: '2026-09-17',
                noHotelAccommodation: false,
            }),
            {
                checkInDate: '2026-09-20',
                lastAutoCheckInDate: '2026-09-20',
            },
        );
    });

    it('preserves a manually edited check-in date', () => {
        assert.equal(
            resolveDestinationCheckInDateOnP2AEntry({
                redeployDate: '2026-09-20',
                currentCheckInDate: '2026-09-21',
                lastAutoCheckInDate: '2026-09-20',
                noHotelAccommodation: false,
            }),
            null,
        );
    });

    it('does not populate check-in when no hotel accommodation is selected', () => {
        assert.equal(
            resolveDestinationCheckInDateOnP2AEntry({
                redeployDate: '2026-09-20',
                currentCheckInDate: '',
                lastAutoCheckInDate: '2026-09-17',
                noHotelAccommodation: true,
            }),
            null,
        );
    });
});
