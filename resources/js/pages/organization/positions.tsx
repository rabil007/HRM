import { Head } from '@inertiajs/react';
import { PositionsContent } from '@/features/organization/positions';
import type { Company, DepartmentOption, Position } from '@/features/organization/positions/types';

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Positions({
    positions,
    companies,
    departments,
}: {
    positions: Pagination<Position>;
    companies: Company[];
    departments: DepartmentOption[];
}) {
    return (
        <>
            <Head title="Positions Management" />
            <PositionsContent positions={positions.data} companies={companies} departments={departments} />
        </>
    );
}

