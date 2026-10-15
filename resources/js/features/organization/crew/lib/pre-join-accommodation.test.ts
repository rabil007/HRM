import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
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
});
