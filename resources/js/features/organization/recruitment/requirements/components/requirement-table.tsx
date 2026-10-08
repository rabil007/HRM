import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
} from '@/components/data-table';
import { TableBody, TableHeader } from '@/components/ui/table';
import type { RequirementIndexRow, RequirementTab } from '@/types/recruitment';
import type { RequirementActionHandlers } from './requirement-action-menu';
import { RequirementEmptyState } from './requirement-empty-state';
import { RequirementMobileCard } from './requirement-mobile-card';
import { RequirementTableRow } from './requirement-table-row';

type Props = RequirementActionHandlers & {
    rows: RequirementIndexRow[];
    activeTab: RequirementTab;
    hasSearch?: boolean;
    hasActiveFilters?: boolean;
    canCreate?: boolean;
    onAddRequirement?: () => void;
    onClearFilters?: () => void;
};

export function RequirementTable({
    rows,
    activeTab,
    hasSearch = false,
    hasActiveFilters = false,
    canCreate = false,
    onAddRequirement,
    onClearFilters,
    onEdit,
    onSubmit,
    onApprove,
    onReturn,
    onResubmit,
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
            <RequirementEmptyState
                activeTab={activeTab}
                hasSearch={hasSearch}
                hasActiveFilters={hasActiveFilters}
                canCreate={canCreate}
                onAddRequirement={onAddRequirement}
                onClearFilters={onClearFilters}
            />
        );
    }

    const handlers: RequirementActionHandlers = {
        onEdit,
        onSubmit,
        onApprove,
        onReturn,
        onResubmit,
        onHold,
        onResume,
        onExtend,
        onChangeHeadcount,
        onFill,
        onCancel,
        onReopen,
        onRepeat,
    };

    return (
        <>
            <div
                className="space-y-3 md:hidden"
                role="list"
                aria-label="Requirements"
            >
                {rows.map((row) => (
                    <div key={row.id} role="listitem">
                        <RequirementMobileCard row={row} {...handlers} />
                    </div>
                ))}
            </div>

            <div className="hidden md:block">
                <OrganizationDataTable minWidth="min-w-[960px]" compact>
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead className="min-w-[240px]">
                                Requirement / Client
                            </DataTableHead>
                            <DataTableHead className="min-w-[220px]">
                                Roles & staffing target
                            </DataTableHead>
                            <DataTableHead className="min-w-[130px]">
                                Target date
                            </DataTableHead>
                            <DataTableHead className="min-w-[120px]">
                                Recruiter
                            </DataTableHead>
                            <DataTableHead className="w-[100px]">
                                Status
                            </DataTableHead>
                            <DataTableHead className="w-[140px] text-right">
                                Actions
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <RequirementTableRow
                                key={row.id}
                                row={row}
                                {...handlers}
                            />
                        ))}
                    </TableBody>
                </OrganizationDataTable>
            </div>
        </>
    );
}
