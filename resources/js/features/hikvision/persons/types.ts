export type HikvisionPersonFilters = {
    search: string;
    group: string;
    credential: string;
};

export type HikvisionPersonFilterOption = {
    value: string;
    label: string;
};

export type HikvisionPerson = {
    id: number;
    person_id: string;
    person_code: string | null;
    full_name: string | null;
    group_name: string | null;
    email: string | null;
    phone: string | null;
    photo_url: string | null;
    has_fingerprint: boolean;
    has_pin: boolean;
    synced_at: string | null;
};
