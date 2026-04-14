import { Head } from '@inertiajs/react';
import { PositionsContent } from '@/features/organization/positions';
import type { DepartmentOption, Position } from '@/features/organization/positions/types';

type Pagination<T> = {
    data: T[];
    links: unknown;
    meta: unknown;
};

export default function Positions({
    positions,
    departments,
}: {
    positions: Pagination<Position>;
    departments: DepartmentOption[];
}) {
    return (
        <>
            <Head title="Positions Management" />
            <PositionsContent positions={positions.data} departments={departments} />
        </>
    );
}

