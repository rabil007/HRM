import type { DepartmentTreeNode } from '@/features/organization/employees/types';
import type { EmployeeContractDetails } from '@/pages/organization/employee-page.types';
import type { PaginationMeta } from '@/types/pagination';

export type ContractLifecycleFilter =
    | 'all'
    | 'active'
    | 'ending_30'
    | 'ending_60'
    | 'ending_90'
    | 'ended';

export type ContractSummary = {
    total_contracts: number;
    active: number;
    ending_30: number;
    ending_60: number;
    ending_90: number;
    ended: number;
    no_contract_employees: number;
};

export type ContractListItem = EmployeeContractDetails & {
    employee_id: number;
    employee_name: string;
    employee_no: string;
    employee_image: string | null;
    department_name?: string | null;
    position_title?: string | null;
    profile_template_name: string | null;
    total_contracts: number;
};

export type ContractEmployeeSummary = {
    id: number;
    name: string;
    employee_no: string;
};

export type ContractBackNavigation = {
    href: string;
    label: string;
};

export type ContractPageCan = {
    view: boolean;
    create: boolean;
    update: boolean;
    delete: boolean;
    import: boolean;
    manage_salary_revisions: boolean;
};

export type ContractSalaryStructureFilter = '' | 'daily' | 'monthly';

export type ContractsIndexProps = {
    summary: ContractSummary;
    lifecycle: ContractLifecycleFilter;
    search: string;
    status: string;
    payroll_category: string;
    salary_structure: ContractSalaryStructureFilter;
    branch_id: string;
    department_id: string;
    contracts: ContractListItem[];
    pagination: PaginationMeta;
    department_tree?: DepartmentTreeNode[];
    department_tree_selected_id?: number | null;
    can: ContractPageCan;
};

export type NoContractEmployee = {
    id: number;
    name: string;
    employee_no: string;
    image: string | null;
    department: string | null;
    position: string | null;
    hire_date: string | null;
};

export type NoContractIndexProps = {
    employees: NoContractEmployee[];
    pagination: PaginationMeta;
    search: string;
    payroll_category: string;
    department_id?: string;
    department_tree?: DepartmentTreeNode[];
    department_tree_selected_id?: number | null;
    can: ContractPageCan;
};

export type ContractEmployeeBrowseProps = {
    employee: ContractEmployeeSummary;
    contracts: EmployeeContractDetails[];
    template_contract_fields: Record<
        string,
        { visible: boolean; required: boolean }
    > | null;
    back: ContractBackNavigation;
    can: ContractPageCan;
};
