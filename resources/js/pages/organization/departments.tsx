import { Head } from '@inertiajs/react';
import { DepartmentsContent } from '@/features/organization/departments';
import type { Branch, Department, DepartmentParentOption, Manager } from '@/features/organization/departments/types';
import type { PaginationMeta } from '@/types/pagination';

export default function Departments({
    departments,
    pagination,
    search,
    filters,
    branches,
    parents,
    managers,
}: {
    departments: Department[];
    pagination: PaginationMeta;
    search: string;
    filters: { branch_id: string; parent_id: string; manager_id: string; status: string; code: string };
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
}) {
    return (
        <>
            <Head title="Departments Management" />
            <DepartmentsContent
                departments={departments}
                pagination={pagination}
                search={search}
                filters={filters}
                branches={branches}
                parents={parents}
                managers={managers}
            />
        </>
    );
}
