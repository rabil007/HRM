import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    applyArrivalDateToAccommodation,
    defaultPreJoinAccommodation,
    preJoinAccommodationPayload,
} from './pre-join-accommodation.ts';

describe('preJoinAccommodationPayload', () => {
    it('starts new already-arrived rows on hotel without a selected stay', () => {
        assert.deepEqual(defaultPreJoinAccommodation(), {
            accommodation_status: 'hotel',
            hotel_id: null,
            room_type_id: null,
            check_in_date: '',
            check_in_date_auto_synced: true,
        });
    });

    it('drops stale hotel fields when the row has no accommodation', () => {
        assert.deepEqual(
            preJoinAccommodationPayload({
                accommodation_status: 'no_accommodation',
                hotel_id: 9,
                room_type_id: 3,
                check_in_date: '2026-10-04',
            }),
            {
                accommodation_status: 'no_accommodation',
                hotel_id: null,
                room_type_id: null,
                check_in_date: null,
            },
        );
    });

    it('keeps hotel check-in details and treats a blank status as hotel', () => {
        assert.deepEqual(
            preJoinAccommodationPayload({
                accommodation_status: '',
                hotel_id: 9,
                room_type_id: null,
                check_in_date: '',
            }),
            {
                accommodation_status: 'hotel',
                hotel_id: 9,
                room_type_id: null,
                check_in_date: null,
            },
        );

        assert.deepEqual(
            preJoinAccommodationPayload({
                accommodation_status: 'hotel',
                hotel_id: 4,
                room_type_id: 8,
                check_in_date: '2026-10-04',
            }),
            {
                accommodation_status: 'hotel',
                hotel_id: 4,
                room_type_id: 8,
                check_in_date: '2026-10-04',
            },
        );
    });

    it('follows the arrival date until the operator chooses a manual hotel date', () => {
        const linked = applyArrivalDateToAccommodation(
            {
                planned_arrival_at: '2026-10-16',
                accommodation_status: 'hotel' as const,
                check_in_date: '2026-10-16',
                check_in_date_auto_synced: true,
                hotel_id: 4,
            },
            '2026-10-20',
        );

        assert.equal(linked.planned_arrival_at, '2026-10-20');
        assert.equal(linked.check_in_date, '2026-10-20');
        assert.equal(linked.hotel_id, 4);

        const manual = applyArrivalDateToAccommodation(
            {
                ...linked,
                check_in_date: '2026-10-19',
                check_in_date_auto_synced: false,
            },
            '2026-10-22',
        );

        assert.equal(manual.planned_arrival_at, '2026-10-22');
        assert.equal(manual.check_in_date, '2026-10-19');
        assert.equal(manual.check_in_date_auto_synced, false);
    });

    it('omits the same-date flag from the submitted accommodation payload', () => {
        assert.equal(
            Object.hasOwn(
                preJoinAccommodationPayload({
                    accommodation_status: 'hotel',
                    hotel_id: 4,
                    room_type_id: null,
                    check_in_date: '2026-10-20',
                    check_in_date_auto_synced: true,
                }),
                'check_in_date_auto_synced',
            ),
            false,
        );
    });
});
