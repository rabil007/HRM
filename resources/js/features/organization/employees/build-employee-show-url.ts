import { show } from '@/actions/App/Http/Controllers/Organization/EmployeeController';

export type EmployeeListQuery = {
    search?: string;
    branch_id?: string;
    department_id?: string;
    position_id?: string;
    status?: string;
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

    return query;
}

export function buildEmployeeShowUrl(
    employeeId: number,
    listQuery: Record<string, string> = {},
): string {
    return show.url({ employee: employeeId }, { query: listQuery });
}
