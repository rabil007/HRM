import { describe, expect, it } from 'vitest';
import { resolveDestinationCheckInDateOnP2AEntry } from './redeploy-destination-check-in';

describe('resolveDestinationCheckInDateOnP2AEntry', () => {
    it('sets check-in to the current redeploy date when empty', () => {
        expect(
            resolveDestinationCheckInDateOnP2AEntry({
                redeployDate: '2026-09-20',
                currentCheckInDate: '',
                lastAutoCheckInDate: '2026-09-17',
                noHotelAccommodation: false,
            }),
        ).toEqual({
            checkInDate: '2026-09-20',
            lastAutoCheckInDate: '2026-09-20',
        });
    });

    it('updates an untouched auto-derived check-in date', () => {
        expect(
            resolveDestinationCheckInDateOnP2AEntry({
                redeployDate: '2026-09-20',
                currentCheckInDate: '2026-09-17',
                lastAutoCheckInDate: '2026-09-17',
                noHotelAccommodation: false,
            }),
        ).toEqual({
            checkInDate: '2026-09-20',
            lastAutoCheckInDate: '2026-09-20',
        });
    });

    it('preserves a manually edited check-in date', () => {
        expect(
            resolveDestinationCheckInDateOnP2AEntry({
                redeployDate: '2026-09-20',
                currentCheckInDate: '2026-09-21',
                lastAutoCheckInDate: '2026-09-20',
                noHotelAccommodation: false,
            }),
        ).toBeNull();
    });

    it('does not populate check-in when no hotel accommodation is selected', () => {
        expect(
            resolveDestinationCheckInDateOnP2AEntry({
                redeployDate: '2026-09-20',
                currentCheckInDate: '',
                lastAutoCheckInDate: '2026-09-17',
                noHotelAccommodation: true,
            }),
        ).toBeNull();
    });
});
