import { show } from '@/actions/App/Http/Controllers/Organization/EmployeeController';

export type EmployeeListQuery = {
    search?: string;
    department_id?: string;
    position_id?: string;
    status?: string;
    manager_id?: string;
    gender_id?: string;
    nationality_id?: string;
    visa_type_id?: string;
    company_visa_type_id?: string;
    rank_id?: string;
    approval_location_id?: string;
    sssa_option_id?: string;
    crew_status?: string;
    role_id?: string;
};

export function buildEmployeeListQuery(
    search: string,
    filters: EmployeeListQuery,
): Record<string, string> {
    const query: Record<string, string> = {};

    if (search.trim() !== '') {
        query.search = search.trim();
    }

    if (filters.department_id) {
        query.department_id = filters.department_id;
    }

    if (filters.position_id) {
        query.position_id = filters.position_id;
    }

    if (filters.status) {
        query.status = filters.status;
    }

    if (filters.manager_id) {
        query.manager_id = filters.manager_id;
    }

    if (filters.gender_id) {
        query.gender_id = filters.gender_id;
    }

    if (filters.nationality_id) {
        query.nationality_id = filters.nationality_id;
    }

    if (filters.visa_type_id) {
        query.visa_type_id = filters.visa_type_id;
    }

    if (filters.company_visa_type_id) {
        query.company_visa_type_id = filters.company_visa_type_id;
    }

    if (filters.rank_id) {
        query.rank_id = filters.rank_id;
    }

    if (filters.approval_location_id) {
        query.approval_location_id = filters.approval_location_id;
    }

    if (filters.sssa_option_id) {
        query.sssa_option_id = filters.sssa_option_id;
    }

    if (filters.crew_status) {
        query.crew_status = filters.crew_status;
    }

    if (filters.role_id) {
        query.role_id = filters.role_id;
    }

    return query;
}

export function buildEmployeeShowUrl(
    employeeId: number,
    listQuery: Record<string, string> = {},
): string {
    return show.url({ employee: employeeId }, { query: listQuery });
}
