import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { roomTypesForHotel } from './accommodation-room-types.ts';

describe('roomTypesForHotel', () => {
    const roomTypes = [
        { id: 1, name: 'Single', hotel_id: 10 },
        { id: 2, name: 'Twin', hotel_id: 10 },
        { id: 3, name: 'Suite', hotel_id: 20 },
        { id: 4, name: 'Legacy', hotel_id: null },
    ];

    it('returns only room types for the selected hotel', () => {
        assert.deepEqual(roomTypesForHotel(roomTypes, 10), [
            { id: 1, name: 'Single', hotel_id: 10 },
            { id: 2, name: 'Twin', hotel_id: 10 },
        ]);
    });

    it('does not include room types from another hotel', () => {
        assert.deepEqual(roomTypesForHotel(roomTypes, 20), [
            { id: 3, name: 'Suite', hotel_id: 20 },
        ]);
    });

    it('does not include legacy unassigned room types', () => {
        const result = roomTypesForHotel(roomTypes, 10);

        assert.equal(
            result.some((roomType) => roomType.id === 4),
            false,
        );
    });

    it('returns an empty list when no hotel is selected', () => {
        assert.deepEqual(roomTypesForHotel(roomTypes, null), []);
        assert.deepEqual(roomTypesForHotel(roomTypes, undefined), []);
    });
});
