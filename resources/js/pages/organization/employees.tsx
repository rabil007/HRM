import { Head } from '@inertiajs/react';
import { EmployeesContent } from '@/features/organization/employees';
import type {
    BranchOption,
    DepartmentOption,
    Employee,
    ManagerOption,
    PositionOption,
    UserOption,
} from '@/features/organization/employees/types';

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Employees({
    employees,
    branches,
    departments,
    positions,
    managers,
    users,
}: {
    employees: Pagination<Employee>;
    branches: BranchOption[];
    departments: DepartmentOption[];
    positions: PositionOption[];
    managers: ManagerOption[];
    users: UserOption[];
}) {
    return (
        <>
            <Head title="Employees" />
            <EmployeesContent
                employees={employees.data}
                branches={branches}
                departments={departments}
                positions={positions}
                managers={managers}
                users={users}
            />
        </>
    );
}

