export type HikvisionEventsFetchStatus = 'idle' | 'queued' | 'running' | 'completed' | 'failed';

export type HikvisionAccessEventFilters = {
    search: string;
    date_from: string;
    date_to: string;
    attendance_status: string;
};

export type HikvisionAttendanceStatusOption = {
    value: string;
    label: string;
};

export type HikvisionAccessEvent = {
    id: number;
    occurrence_time: string | null;
    person_name: string | null;
    device_name: string | null;
    door_no: string | null;
    resource_name: string | null;
    card_reader_no: string | null;
    verify_mode: string | null;
    attendance_status: string | null;
    fetched_at: string | null;
};
