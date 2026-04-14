import { Head } from '@inertiajs/react';
import { DepartmentsContent } from '@/features/organization/departments';
import type { Branch, Department, DepartmentParentOption, Manager } from '@/features/organization/departments/types';

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Departments({
    departments,
    branches,
    parents,
    managers,
}: {
    departments: Pagination<Department>;
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
}) {
    return (
        <>
            <Head title="Departments Management" />
            <DepartmentsContent
                departments={departments.data}
                branches={branches}
                parents={parents}
                managers={managers}
            />
        </>
    );
}

