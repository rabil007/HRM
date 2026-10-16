export type PreJoinAccommodationFields = {
    accommodation_status: 'hotel' | 'no_accommodation' | '';
    hotel_id: number | null;
    room_type_id: number | null;
    check_in_date: string;
    check_in_date_auto_synced?: boolean;
};

export function defaultPreJoinAccommodation(): PreJoinAccommodationFields {
    return {
        accommodation_status: 'hotel',
        hotel_id: null,
        room_type_id: null,
        check_in_date: '',
        check_in_date_auto_synced: true,
    };
}

export function applyArrivalDateToAccommodation<
    T extends {
        planned_arrival_at?: string | null;
        accommodation_status?: 'hotel' | 'no_accommodation' | '';
        check_in_date?: string;
        check_in_date_auto_synced?: boolean;
    },
>(row: T, arrival: string | null): T {
    const next: T = {
        ...row,
        planned_arrival_at: arrival,
    };

    if (
        row.accommodation_status === 'no_accommodation' ||
        row.check_in_date_auto_synced === false
    ) {
        return next;
    }

    return {
        ...next,
        check_in_date: (arrival ?? '').slice(0, 10),
        check_in_date_auto_synced: true,
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
