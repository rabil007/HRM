export type HikvisionEventsFetchStatus = 'idle' | 'queued' | 'running' | 'completed' | 'failed';

export type HikvisionAccessEventFilters = {
    search: string;
    date_from: string;
    date_to: string;
    attendance_status: string;
    device: string;
};

export type HikvisionAttendanceStatusOption = {
    value: string;
    label: string;
};

export type HikvisionAccessEvent = {
    id: number;
    occurrence_time: string | null;
    person_name: string | null;
    employee_name: string | null;
    employee_id: number | null;
    device_name: string | null;
    door_no: string | null;
    resource_name: string | null;
    card_reader_no: string | null;
    verify_mode: string | null;
    attendance_status: string | null;
    transaction_source: string | null;
    event_source: string | null;
    snap_urls: string[];
    fetched_at: string | null;
};
