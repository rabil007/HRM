import { contracts } from '@/routes/organization';

export function buildContractIndexUrl(options?: {
    createForEmployeeId?: number;
    editContractId?: number;
    search?: string;
    lifecycle?: string;
    status?: string;
    payroll_category?: string;
    salary_structure?: string;
    branch_id?: string;
    department_id?: string;
    page?: number;
}): string {
    const query: Record<string, string> = {};

    if (options?.createForEmployeeId && options.createForEmployeeId > 0) {
        query.create_for = String(options.createForEmployeeId);
    }

    if (options?.editContractId && options.editContractId > 0) {
        query.edit = String(options.editContractId);
    }

    if (options?.search?.trim()) {
        query.search = options.search.trim();
    }

    if (options?.lifecycle && options.lifecycle !== 'all') {
        query.lifecycle = options.lifecycle;
    }

    if (options?.status?.trim()) {
        query.status = options.status.trim();
    }

    if (options?.payroll_category?.trim()) {
        query.payroll_category = options.payroll_category.trim();
    }

    if (options?.salary_structure?.trim()) {
        query.salary_structure = options.salary_structure.trim();
    }

    if (options?.branch_id?.trim()) {
        query.branch_id = options.branch_id.trim();
    }

    if (options?.department_id?.trim()) {
        query.department_id = options.department_id.trim();
    }

    if (options?.page && options.page > 1) {
        query.page = String(options.page);
    }

    return contracts.url(Object.keys(query).length > 0 ? { query } : undefined);
}
