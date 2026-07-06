import type { SalaryPaymentMethodValue } from '@/features/organization/employees/salary-payment-method';
import type { EmployeeBankAccountItem } from '@/pages/organization/employee-page.types';
import type { PaginationMeta } from '@/types/pagination';

export type BankAccountSummary = {
    total_bank_accounts: number;
    primary_accounts: number;
    secondary_accounts: number;
    no_account_employees: number;
};

export type BankAccountListItem = EmployeeBankAccountItem & {
    employee_id: number;
    employee_name: string;
    employee_no: string;
    employee_image: string | null;
    profile_template_name: string | null;
    salary_payment_method?: SalaryPaymentMethodValue;
    salary_payment_method_label?: string;
    total_bank_accounts: number;
};

export type BankAccountEmployeeSummary = {
    id: number;
    name: string;
    employee_no: string;
};

export type BankAccountBackNavigation = {
    href: string;
    label: string;
};

export type BankAccountPageCan = {
    view: boolean;
    create: boolean;
    update: boolean;
    delete: boolean;
    import: boolean;
};

export type BankOption = {
    id: number;
    name: string;
};

export type BankAccountsIndexProps = {
    summary: BankAccountSummary;
    search: string;
    bank_id: string;
    is_primary: string;
    branch_id: string;
    department_id: string;
    bank_accounts: BankAccountListItem[];
    banks: BankOption[];
    pagination: PaginationMeta;
    can: BankAccountPageCan;
};

export type NoBankAccountEmployee = {
    id: number;
    name: string;
    employee_no: string;
    image: string | null;
    department: string | null;
    position: string | null;
    hire_date: string | null;
    salary_payment_method?: SalaryPaymentMethodValue;
    salary_payment_method_label?: string;
};

export type NoBankAccountSummary = {
    total_no_account: number;
    bank_transfer: number;
    cash_c3: number;
    cash_other: number;
};

export type NoBankAccountIndexProps = {
    summary: NoBankAccountSummary;
    employees: NoBankAccountEmployee[];
    pagination: PaginationMeta;
    search: string;
    payment_method?: string;
    can: BankAccountPageCan;
};

export type BankAccountEmployeeBrowseProps = {
    employee: BankAccountEmployeeSummary;
    bank_accounts: EmployeeBankAccountItem[];
    banks: BankOption[];
    template_bank_account_fields: Record<
        string,
        { visible: boolean; required: boolean }
    > | null;
    back: BankAccountBackNavigation;
    can: BankAccountPageCan;
};
