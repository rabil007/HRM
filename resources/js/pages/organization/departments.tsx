import { Head } from '@inertiajs/react';
import { DepartmentsContent } from '@/features/organization/departments';
import type { Branch, Company, Department, DepartmentParentOption, Manager } from '@/features/organization/departments/types';

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Departments({
    departments,
    companies,
    branches,
    parents,
    managers,
}: {
    departments: Pagination<Department>;
    companies: Company[];
    branches: Branch[];
    parents: DepartmentParentOption[];
    managers: Manager[];
}) {
    return (
        <>
            <Head title="Departments Management" />
            <DepartmentsContent
                departments={departments.data}
                companies={companies}
                branches={branches}
                parents={parents}
                managers={managers}
            />
        </>
    );
}

