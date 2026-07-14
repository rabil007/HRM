import type { ContractLifecycleFilter } from '@/features/organization/contracts/types';
import { employee } from '@/routes/organization/contracts';

export type ContractEmployeeBackContext = {
    from: 'index';
    search?: string;
    lifecycle?: ContractLifecycleFilter;
    status?: string;
    payroll_category?: string;
    salary_structure?: string;
    branch_id?: string;
    department_id?: string;
    page?: number;
};

export function buildContractEmployeeUrl(
    employeeId: number,
    back: ContractEmployeeBackContext = { from: 'index' },
    options?: { editContractId?: number },
): string {
    const query: Record<string, string> = {
        from: back.from,
    };

    if (back.lifecycle && back.lifecycle !== 'all') {
        query.lifecycle = back.lifecycle;
    }

    if (back.search?.trim()) {
        query.search = back.search.trim();
    }

    if (back.status?.trim()) {
        query.status = back.status.trim();
    }

    if (back.payroll_category?.trim()) {
        query.payroll_category = back.payroll_category.trim();
    }

    if (back.salary_structure?.trim()) {
        query.salary_structure = back.salary_structure.trim();
    }

    if (back.branch_id?.trim()) {
        query.branch_id = back.branch_id.trim();
    }

    if (back.department_id?.trim()) {
        query.department_id = back.department_id.trim();
    }

    if (back.page && back.page > 1) {
        query.page = String(back.page);
    }

    if (options?.editContractId) {
        query.edit = String(options.editContractId);
    }

    return employee.url({ employee: employeeId }, { query });
}
