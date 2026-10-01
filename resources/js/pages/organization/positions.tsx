import { Head } from '@inertiajs/react';
import type { DepartmentTreeNode } from '@/features/organization/employees/types';
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
    department_tree = [],
    department_tree_selected_id = null,
}: {
    positions: Position[];
    pagination: PaginationMeta;
    search: string;
    filters: { department_id: string; status: string; grade: string };
    departments: DepartmentOption[];
    tree_departments?: any[];
    tree_positions?: any[];
    department_tree?: DepartmentTreeNode[];
    department_tree_selected_id?: number | null;
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
                department_tree={department_tree}
                department_tree_selected_id={department_tree_selected_id}
            />
        </>
    );
}
