import { show } from '@/actions/App/Http/Controllers/Organization/EmployeeController';

export type EmployeeListQuery = {
    search?: string;
    branch_id?: string;
    department_id?: string;
    position_id?: string;
    status?: string;
    manager_id?: string;
    gender_id?: string;
    nationality_id?: string;
    visa_type_id?: string;
    company_visa_type_id?: string;
    rank_id?: string;
};

export function buildEmployeeListQuery(
    search: string,
    filters: EmployeeListQuery,
): Record<string, string> {
    const query: Record<string, string> = {};

    if (search.trim() !== '') {
        query.search = search.trim();
    }

    if (filters.branch_id) {
        query.branch_id = filters.branch_id;
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

    return query;
}

export function buildEmployeeShowUrl(
    employeeId: number,
    listQuery: Record<string, string> = {},
): string {
    return show.url({ employee: employeeId }, { query: listQuery });
}
