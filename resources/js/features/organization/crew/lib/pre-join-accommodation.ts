export type PreJoinAccommodationFields = {
    accommodation_status: 'hotel' | 'no_accommodation' | '';
    hotel_id: number | null;
    room_type_id: number | null;
    check_in_date: string;
};

export function defaultPreJoinAccommodation(): PreJoinAccommodationFields {
    return {
        accommodation_status: 'hotel',
        hotel_id: null,
        room_type_id: null,
        check_in_date: '',
    };
}

export function preJoinAccommodationPayload(
    fields: PreJoinAccommodationFields,
): Record<string, string | number | null> {
    if (fields.accommodation_status === 'no_accommodation') {
        return {
            accommodation_status: 'no_accommodation',
            hotel_id: null,
            room_type_id: null,
            check_in_date: null,
        };
    }

    return {
        accommodation_status: fields.accommodation_status || 'hotel',
        hotel_id: fields.hotel_id,
        room_type_id: fields.room_type_id,
        check_in_date: fields.check_in_date || null,
    };
}
