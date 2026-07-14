import type { ContractEmployeeBackContext } from '@/features/organization/contracts/build-contract-employee-url';
import { show as contractShow } from '@/routes/organization/contracts';

export type ContractShowBackContext =
    | ContractEmployeeBackContext
    | {
          from: 'employee';
          employee_id: number;
          search?: string;
          lifecycle?: string;
          status?: string;
          payroll_category?: string;
          salary_structure?: string;
          branch_id?: string;
          department_id?: string;
          page?: number;
      }
    | {
          from: 'profile';
          employee_id: number;
      };

export function buildContractShowUrl(
    contractId: number,
    back: ContractShowBackContext = { from: 'index' },
): string {
    const query: Record<string, string> = {
        from: back.from,
    };

    if (back.from === 'employee' || back.from === 'profile') {
        query.employee_id = String(back.employee_id);
    }

    if (back.from === 'index' || back.from === 'employee') {
        if ('lifecycle' in back && back.lifecycle && back.lifecycle !== 'all') {
            query.lifecycle = String(back.lifecycle);
        }

        if ('search' in back && back.search?.trim()) {
            query.search = back.search.trim();
        }

        if ('status' in back && back.status?.trim()) {
            query.status = back.status.trim();
        }

        if ('payroll_category' in back && back.payroll_category?.trim()) {
            query.payroll_category = back.payroll_category.trim();
        }

        if ('salary_structure' in back && back.salary_structure?.trim()) {
            query.salary_structure = back.salary_structure.trim();
        }

        if ('branch_id' in back && back.branch_id?.trim()) {
            query.branch_id = back.branch_id.trim();
        }

        if ('department_id' in back && back.department_id?.trim()) {
            query.department_id = back.department_id.trim();
        }

        if ('page' in back && back.page && back.page > 1) {
            query.page = String(back.page);
        }
    }

    return contractShow.url(contractId, { query });
}
