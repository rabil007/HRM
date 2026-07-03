import type { EmployeeContractDetails } from '@/pages/organization/employee-page.types';
import type { PaginationMeta } from '@/types/pagination';

export type ContractLifecycleFilter =
    | 'all'
    | 'active'
    | 'ending_30'
    | 'ending_60'
    | 'ending_90'
    | 'ended'
    | 'draft';

export type ContractSummary = {
    total_contracts: number;
    active: number;
    ending_30: number;
    ending_60: number;
    ending_90: number;
    ended: number;
    draft: number;
};

export type ContractListItem = EmployeeContractDetails & {
    employee_id: number;
    employee_name: string;
    employee_no: string;
    employee_image: string | null;
    profile_template_name: string | null;
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
};

export type ContractsIndexProps = {
    summary: ContractSummary;
    lifecycle: ContractLifecycleFilter;
    search: string;
    status: string;
    payroll_category: string;
    branch_id: string;
    department_id: string;
    contracts: ContractListItem[];
    pagination: PaginationMeta;
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
