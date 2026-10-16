import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { resolveDestinationCheckInDateOnP1AEntry } from './redeploy-destination-check-in.ts';

describe('resolveDestinationCheckInDateOnP1AEntry', () => {
    it('sets check-in to the redeploy date when same-date sync is enabled', () => {
        assert.equal(
            resolveDestinationCheckInDateOnP1AEntry({
                redeployDate: '2026-09-20',
                autoSynced: true,
                noHotelAccommodation: false,
            }),
            '2026-09-20',
        );
    });

    it('preserves a manual check-in even when it equals the previous movement date', () => {
        assert.equal(
            resolveDestinationCheckInDateOnP1AEntry({
                redeployDate: '2026-09-20',
                autoSynced: false,
                noHotelAccommodation: false,
            }),
            null,
        );
    });

    it('does not populate check-in when no hotel accommodation is selected', () => {
        assert.equal(
            resolveDestinationCheckInDateOnP1AEntry({
                redeployDate: '2026-09-20',
                autoSynced: true,
                noHotelAccommodation: true,
            }),
            null,
        );
    });
});
