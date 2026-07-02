import { Head } from '@inertiajs/react';
import { PositionsContent } from '@/features/organization/positions';
import type {
    DepartmentOption,
    Position,
} from '@/features/organization/positions/types';
import type { PaginationMeta } from '@/types/pagination';

export default function Positions({
    positions,
    pagination,
    search,
    filters,
    departments,
    tree_departments = [],
    tree_positions = [],
}: {
    positions: Position[];
    pagination: PaginationMeta;
    search: string;
    filters: { department_id: string; status: string; grade: string };
    departments: DepartmentOption[];
    tree_departments?: any[];
    tree_positions?: any[];
}) {
    return (
        <>
            <Head title="Positions Management" />
            <PositionsContent
                positions={positions}
                pagination={pagination}
                search={search}
                filters={filters}
                departments={departments}
                tree_departments={tree_departments}
                tree_positions={tree_positions}
            />
        </>
    );
}
