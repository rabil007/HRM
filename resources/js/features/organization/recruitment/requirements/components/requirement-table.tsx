import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { TableBody, TableHeader } from '@/components/ui/table';
import type { RequirementIndexRow } from '@/types/recruitment';
import { RequirementTableRow } from './requirement-table-row';

type Props = {
    rows: RequirementIndexRow[];
    onEdit: (row: RequirementIndexRow) => void;
    onOpen: (row: RequirementIndexRow) => void;
    onHold: (row: RequirementIndexRow) => void;
    onResume: (row: RequirementIndexRow) => void;
    onExtend: (row: RequirementIndexRow) => void;
    onChangeHeadcount: (row: RequirementIndexRow) => void;
    onFill: (row: RequirementIndexRow) => void;
    onCancel: (row: RequirementIndexRow) => void;
    onReopen: (row: RequirementIndexRow) => void;
    onRepeat: (row: RequirementIndexRow) => void;
};

export function RequirementTable({
    rows,
    onEdit,
    onOpen,
    onHold,
    onResume,
    onExtend,
    onChangeHeadcount,
    onFill,
    onCancel,
    onReopen,
    onRepeat,
}: Props) {
    if (rows.length === 0) {
        return (
            <EmptyState
                title="No recruitment requirements found"
                description="Try changing the tab or adjusting your search and filter criteria."
            />
        );
    }

    return (
        <OrganizationDataTable minWidth="1050px" compact>
            <TableHeader>
                <DataTableHeaderRow>
                    <DataTableHead className="w-[160px]">Req #</DataTableHead>
                    <DataTableHead className="min-w-[180px]">
                        Client & Project
                    </DataTableHead>
                    <DataTableHead className="min-w-[200px]">
                        Positions & Headcount
                    </DataTableHead>
                    <DataTableHead className="w-[100px]">
                        Priority
                    </DataTableHead>
                    <DataTableHead className="min-w-[140px]">
                        Required By
                    </DataTableHead>
                    <DataTableHead className="w-[110px]">Status</DataTableHead>
                    <DataTableHead className="min-w-[130px]">
                        Recruiter
                    </DataTableHead>
                    <DataTableHead className="w-[130px] text-right">
                        Actions
                    </DataTableHead>
                </DataTableHeaderRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <RequirementTableRow
                        key={row.id}
                        row={row}
                        onEdit={onEdit}
                        onOpen={onOpen}
                        onHold={onHold}
                        onResume={onResume}
                        onExtend={onExtend}
                        onChangeHeadcount={onChangeHeadcount}
                        onFill={onFill}
                        onCancel={onCancel}
                        onReopen={onReopen}
                        onRepeat={onRepeat}
                    />
                ))}
            </TableBody>
        </OrganizationDataTable>
    );
}
