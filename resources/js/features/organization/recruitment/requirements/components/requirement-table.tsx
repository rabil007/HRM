import { ClipboardList } from 'lucide-react';
import {
    DataTableHead,
    DataTableHeaderRow,
    OrganizationDataTable,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { TableBody, TableHeader } from '@/components/ui/table';
import type { RequirementIndexRow, RequirementTab } from '@/types/recruitment';
import { RequirementMobileCard } from './requirement-mobile-card';
import { RequirementTableRow } from './requirement-table-row';

type ActionHandlers = {
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

type Props = ActionHandlers & {
    rows: RequirementIndexRow[];
    activeTab: RequirementTab;
    /** True when a search term is active */
    hasSearch?: boolean;
    /** True when any filter (besides tab) is active */
    hasActiveFilters?: boolean;
    /** Whether the current user can create requirements */
    canCreate?: boolean;
    /** Called when the user clicks "Add Requirement" from the empty state */
    onAddRequirement?: () => void;
    /** Called when the user clicks "Clear filters" from the no-results state */
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
        const isFiltering = hasSearch || hasActiveFilters;

        if (isFiltering) {
            // No results for current search/filters
            return (
                <EmptyState
                    icon={
                        <ClipboardList
                            className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40"
                            aria-hidden="true"
                        />
                    }
                    title="No matching requirements"
                    description="No requirements match your current search or filter criteria."
                    action={
                        onClearFilters ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={onClearFilters}
                                className="gap-2"
                            >
                                Clear search & filters
                            </Button>
                        ) : undefined
                    }
                />
            );
        }

        // Module is empty
        return (
            <EmptyState
                icon={
                    <ClipboardList
                        className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40"
                        aria-hidden="true"
                    />
                }
                title={
                    activeTab === 'history'
                        ? 'No completed or cancelled requirements'
                        : activeTab === 'on_hold'
                          ? 'No requirements on hold'
                          : 'Start your next hire here'
                }
                description={
                    activeTab === 'history'
                        ? 'Completed and cancelled requests will appear here for reference.'
                        : activeTab === 'on_hold'
                          ? 'Paused requests appear here. Resume them when recruitment is ready to continue.'
                          : 'Create a staffing request, add the roles and headcount you need, and assign a recruiter to keep things moving.'
                }
                action={
                    activeTab === 'active' && canCreate && onAddRequirement ? (
                        <Button
                            type="button"
                            size="sm"
                            onClick={onAddRequirement}
                            className="gap-2"
                        >
                            Add Requirement
                        </Button>
                    ) : undefined
                }
            />
        );
    }

    const handlers: ActionHandlers = {
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
    };

    return (
        <>
            {/* Mobile list — visible below md breakpoint */}
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

            {/* Desktop table — visible at md and above */}
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
                                Required By
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
