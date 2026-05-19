import { Head } from '@inertiajs/react';
import { EmployeesContent } from '@/features/organization/employees';
import type {
    BranchOption,
    BankOption,
    CountryOption,
    DepartmentOption,
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
    branches,
    departments,
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
    branches: BranchOption[];
    departments: DepartmentOption[];
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
                branches={branches}
                departments={departments}
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
