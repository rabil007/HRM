import { Head } from '@inertiajs/react';
import { EmployeesContent } from '@/features/organization/employees';
import type {
    BranchOption,
    BankOption,
    CompanyVisaTypeOption,
    CountryOption,
    DepartmentTreeNode,
    Employee,
    GenderOption,
    ManagerOption,
    PositionOption,
    RankOption,
    ReligionOption,
    UserOption,
    VisaTypeOption,
} from '@/features/organization/employees/types';
import type { PaginationMeta } from '@/types/pagination';

export default function Employees({
    employees,
    pagination,
    search,
    filters,
    department_tree,
    department_tree_selected_id,
    branches,
    positions,
    managers,
    users,
    countries,
    religions,
    genders,
    visa_types,
    company_visa_types,
    ranks,
    banks,
}: {
    employees: Employee[];
    pagination: PaginationMeta;
    search: string;
    filters: {
        branch_id: string;
        department_id: string;
        position_id: string;
        status: string;
        manager_id: string;
        gender_id: string;
        nationality_id: string;
        visa_type_id: string;
        company_visa_type_id: string;
        rank_id: string;
    };
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    branches: BranchOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
    countries: CountryOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
    visa_types: VisaTypeOption[];
    company_visa_types: CompanyVisaTypeOption[];
    ranks: RankOption[];
    banks: BankOption[];
}) {
    return (
        <>
            <Head title="Employees" />
            <EmployeesContent
                employees={employees}
                pagination={pagination}
                search={search}
                filters={filters}
                department_tree={department_tree}
                department_tree_selected_id={department_tree_selected_id}
                branches={branches}
                positions={positions}
                managers={managers}
                users={users}
                countries={countries}
                religions={religions}
                genders={genders}
                visa_types={visa_types}
                company_visa_types={company_visa_types}
                ranks={ranks}
                banks={banks}
            />
        </>
    );
}
