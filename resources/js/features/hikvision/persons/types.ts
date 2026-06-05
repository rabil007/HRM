export type HikvisionPerson = {
    id: number;
    person_id: string;
    name: string;
    phone: string | null;
    email: string | null;
    is_expired: boolean;
    synced_at: string | null;
};
