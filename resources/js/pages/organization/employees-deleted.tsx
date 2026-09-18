import { Head } from '@inertiajs/react';
import { DeletedEmployeesContent } from '@/features/organization/employees/deleted-employees-content';
import type {
    DeletedEmployee,
    EmployeePageCan,
} from '@/features/organization/employees/types';
import type { PaginationMeta } from '@/types/pagination';

export default function EmployeesDeleted({
    employees,
    pagination,
    search,
    can,
}: {
    employees: DeletedEmployee[];
    pagination: PaginationMeta;
    search: string;
    can: EmployeePageCan;
}) {
    return (
        <>
            <Head title="Deleted Employees" />
            <DeletedEmployeesContent
                employees={employees}
                pagination={pagination}
                search={search}
                can={can}
            />
        </>
    );
}
