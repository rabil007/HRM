export type HikvisionPersonFilters = {
    search: string;
    group: string;
    credential: string;
};

export type HikvisionPersonFilterOption = {
    value: string;
    label: string;
};

export type HikvisionPersonLinkedEmployee = {
    id: number;
    name: string;
    employee_no: string | null;
};

export type EmployeeLinkOption = HikvisionPersonLinkedEmployee;

export type HikvisionPerson = {
    id: number;
    person_id: string;
    person_code: string | null;
    full_name: string | null;
    first_name?: string | null;
    last_name?: string | null;
    group_id?: string | null;
    group_name: string | null;
    email: string | null;
    phone: string | null;
    photo_url: string | null;
    has_fingerprint: boolean;
    has_pin: boolean;
    linked_employee?: HikvisionPersonLinkedEmployee | null;
    synced_at: string | null;
};

export type HikvisionPersonFormData = {
    first_name: string;
    last_name: string;
    group_id: string;
    person_code: string;
    email: string;
    phone: string;
};
