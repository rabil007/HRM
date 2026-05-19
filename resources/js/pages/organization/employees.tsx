import { Head } from '@inertiajs/react';
import { EmployeesContent } from '@/features/organization/employees';
import type {
    BranchOption,
    BankOption,
    CountryOption,
    DepartmentTreeNode,
    Employee,
    GenderOption,
    ManagerOption,
    PositionOption,
    ReligionOption,
    UserOption,
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
    banks,
}: {
    employees: Employee[];
    pagination: PaginationMeta;
    search: string;
    filters: { branch_id: string; department_id: string; position_id: string; status: string };
    department_tree: DepartmentTreeNode[];
    department_tree_selected_id: number | null;
    branches: BranchOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
    countries: CountryOption[];
    religions: ReligionOption[];
    genders: GenderOption[];
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
                banks={banks}
            />
        </>
    );
}
