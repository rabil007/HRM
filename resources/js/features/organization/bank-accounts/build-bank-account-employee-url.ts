import { employee } from '@/routes/organization/bank-accounts';

export type BankAccountEmployeeBackContext = {
    from: 'index';
    search?: string;
    bank_id?: string;
    is_primary?: string;
    payment_method?: string;
    branch_id?: string;
    department_id?: string;
    page?: number;
};

export function buildBankAccountEmployeeUrl(
    employeeId: number,
    back: BankAccountEmployeeBackContext = { from: 'index' },
): string {
    const query: Record<string, string> = {
        from: back.from,
    };

    if (back.search?.trim()) {
        query.search = back.search.trim();
    }

    if (back.bank_id?.trim()) {
        query.bank_id = back.bank_id.trim();
    }

    if (back.is_primary?.trim()) {
        query.is_primary = back.is_primary.trim();
    }

    if (back.payment_method?.trim()) {
        query.payment_method = back.payment_method.trim();
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

    return employee.url({ employee: employeeId }, { query });
}
