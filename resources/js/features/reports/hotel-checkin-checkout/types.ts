export type HotelCheckInCheckoutRow = {
    id: number;
    stay_type: 'pre_join' | 'post_signoff' | null;
    stay_type_label: string;
    accommodation_status: 'hotel' | 'no_accommodation' | null;
    accommodation_status_label: string;
    check_in_date: string | null;
    check_out_date: string | null;
    is_open: boolean;
    stay_days: number | null;
    stay_status: string;
    stay_status_label: string;
    hotel: {
        id: number | null;
        name: string;
    };
    room_type: {
        id: number | null;
        name: string;
    };
    starting_checkpoint: string | null;
    current_phase: string | null;
    assignment: {
        id: number;
        assignment_no: string;
        status: string | null;
        status_label: string;
        vessel_id: number | null;
        vessel_name: string;
        rank_id: number | null;
        rank_name: string;
        client_id: number | null;
        client_name: string;
    };
    employee: {
        id: number | null;
        employee_no: string;
        name: string;
    };
};

export type HotelCheckInCheckoutSummary = {
    total: number;
    currently_checked_in: number;
    check_in_today: number;
    checking_out_today: number;
    upcoming: number;
    checked_out: number;
};

export type HotelCheckInCheckoutFilters = {
    search: string;
    hotel_id: string;
    room_type_id: string;
    stay_type: string;
    stay_status: string;
    accommodation_status: string;
    check_in_from: string;
    check_in_to: string;
    check_out_from: string;
    check_out_to: string;
    vessel_id: string;
    rank_id: string;
    client_id: string;
    sort: string;
    direction: 'asc' | 'desc';
};

export type HotelCheckInCheckoutFilterOptions = {
    hotels: Array<{ id: number; name: string }>;
    room_types: Array<{ id: number; hotel_id: number | null; name: string }>;
    stay_types: Array<{ value: string; label: string }>;
    stay_statuses: Array<{ value: string; label: string }>;
    accommodation_statuses: Array<{ value: string; label: string }>;
    vessels: Array<{ id: number; name: string }>;
    ranks: Array<{ id: number; name: string }>;
    clients: Array<{ id: number; name: string }>;
};

export type HotelCheckInCheckoutProps = {
    stays: HotelCheckInCheckoutRow[];
    pagination: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    summary: HotelCheckInCheckoutSummary;
    filters: HotelCheckInCheckoutFilters;
    filter_options: HotelCheckInCheckoutFilterOptions;
    company_today: string;
    can: {
        export: boolean;
    };
};
