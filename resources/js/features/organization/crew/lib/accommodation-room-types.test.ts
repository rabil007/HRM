import { describe, expect, it } from 'vitest';
import { roomTypesForHotel } from './accommodation-room-types';

describe('roomTypesForHotel', () => {
    const roomTypes = [
        { id: 1, name: 'Single', hotel_id: 10 },
        { id: 2, name: 'Twin', hotel_id: 10 },
        { id: 3, name: 'Suite', hotel_id: 20 },
    ];

    it('returns only room types for the selected hotel', () => {
        expect(roomTypesForHotel(roomTypes, 10)).toEqual([
            { id: 1, name: 'Single', hotel_id: 10 },
            { id: 2, name: 'Twin', hotel_id: 10 },
        ]);
    });

    it('keeps a selected inactive or historical room type visible', () => {
        expect(roomTypesForHotel(roomTypes, 10, 3)).toEqual([
            { id: 1, name: 'Single', hotel_id: 10 },
            { id: 2, name: 'Twin', hotel_id: 10 },
            { id: 3, name: 'Suite', hotel_id: 20 },
        ]);
    });
});
