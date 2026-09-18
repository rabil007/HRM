import {
    ChevronDown,
    Download,
    FileSpreadsheet,
    FileText,
    Filter,
    Loader2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { DateRangeFilter } from '@/components/date-range-filter';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import { exportMethod } from '@/routes/organization/reports/leave';
import { LeaveReportFiltersSheet } from './filters-sheet';
import {
    LeaveReportActiveFilters,
    countSheetFilters,
} from './leave-report-active-filters';
import { LeaveReportTable } from './report-table';
import { LeaveReportSummaryCards } from './summary-cards';
import type { LeaveReportProps } from './types';
import { useLeaveReportFilters } from './use-leave-report-filters';

export function LeaveReportContent(props: LeaveReportProps) {
    const {
        leave_requests: leaveRequests,
        pagination,
        summary,
        filters,
        filter_options: options,
        department_tree: departmentTree,
        department_tree_selected_id: departmentTreeSelectedId,
        can,
    } = props;
    const [filtersOpen, setFiltersOpen] = useState(false);
    const controls = useLeaveReportFilters(filters, pagination.per_page);
    const sheetFiltersCount = useMemo(
        () => countSheetFilters(filters),
        [filters],
    );
    const hasActiveFilters =
        sheetFiltersCount > 0 ||
        filters.leave_from !== '' ||
        filters.leave_to !== '' ||
        filters.department_id !== '' ||
        filters.leave_type_id !== '' ||
        controls.searchInput.trim() !== '';

    const exportUrl = (format: 'xlsx' | 'csv'): string =>
        exportMethod.url({
            query: {
                ...Object.fromEntries(
                    Object.entries(filters).filter(([, value]) => value !== ''),
                ),
                format,
            },
        });

    return (
        <Main>
            <PageHeader
                kicker="Reports"
                title="Leave Report"
                description="Review employee leave history, status and leave usage across the selected period."
                right={
                    can.export ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline">
                                    <Download className="mr-2 size-4" />
                                    Export report
                                    <ChevronDown className="ml-2 size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem asChild>
                                    <a href={exportUrl('xlsx')}>
                                        <FileSpreadsheet className="size-4" />
                                        Excel workbook
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={exportUrl('csv')}>
                                        <FileText className="size-4" />
                                        CSV file
                                    </a>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null
                }
            />

            <LeaveReportSummaryCards
                summary={summary}
                filters={filters}
                onSelect={controls.apply}
            />

            <div className="mt-6 space-y-3">
                <SearchBar
                    placeholder="Search employee name or number..."
                    value={controls.searchInput}
                    onChange={controls.changeSearch}
                    right={
                        <div className="flex flex-wrap items-center gap-2">
                            <DateRangeFilter
                                label="Leave period"
                                hint="Show leave that overlaps this period."
                                from={filters.leave_from}
                                to={filters.leave_to}
                                onChange={({ from, to }) =>
                                    controls.apply({
                                        leave_from: from,
                                        leave_to: to,
                                    })
                                }
                            />
                            {departmentTree.length > 0 ? (
                                <DepartmentFilterControls
                                    department_tree={departmentTree}
                                    department_tree_selected_id={
                                        departmentTreeSelectedId
                                    }
                                    department_tree_selected_position_id={null}
                                    onSelectDepartment={(id) =>
                                        controls.apply({
                                            department_id:
                                                id != null ? String(id) : '',
                                        })
                                    }
                                    onSelectPosition={(_, departmentId) =>
                                        controls.apply({
                                            department_id: String(departmentId),
                                        })
                                    }
                                    buttonClassName="h-11"
                                />
                            ) : null}
                            <AppSelect
                                value={filters.leave_type_id}
                                onValueChange={(value) =>
                                    controls.apply({ leave_type_id: value })
                                }
                                variant="dark"
                                placeholder="All leave types"
                                className="h-11 w-[11rem]"
                                searchPlaceholder="Search leave types..."
                            >
                                <AppSelectItem value="">
                                    All leave types
                                </AppSelectItem>
                                {options.leave_types.map((leaveType) => (
                                    <AppSelectItem
                                        key={leaveType.id}
                                        value={String(leaveType.id)}
                                    >
                                        {leaveType.name}
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                            {controls.isLoading ? (
                                <Loader2 className="size-4 animate-spin text-muted-foreground" />
                            ) : null}
                            <Button
                                type="button"
                                variant="secondary"
                                className="h-11"
                                onClick={() => setFiltersOpen(true)}
                            >
                                <Filter className="mr-2 size-4" />
                                Filters
                                {sheetFiltersCount ? (
                                    <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                        {sheetFiltersCount}
                                    </span>
                                ) : null}
                            </Button>
                            {hasActiveFilters ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11 rounded-xl px-4 hover:bg-accent"
                                    onClick={controls.clear}
                                >
                                    Clear all
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <LeaveReportActiveFilters
                    filters={filters}
                    searchInput={controls.searchInput}
                    options={options}
                    onClearSearch={() => controls.changeSearch('')}
                    onApply={controls.apply}
                />
            </div>

            <div className="mt-4">
                {leaveRequests.length === 0 ? (
                    <EmptyState
                        title="No leave requests found"
                        description="Try changing or clearing the report filters."
                    />
                ) : (
                    <LeaveReportTable
                        rows={leaveRequests}
                        total={pagination.total}
                        filters={filters}
                        onSort={controls.sort}
                    />
                )}
            </div>

            <Pagination
                currentPage={pagination.current_page}
                lastPage={pagination.last_page}
                from={pagination.from}
                to={pagination.to}
                total={pagination.total}
                perPage={pagination.per_page}
                perPageOptions={[25, 50, 100]}
                onPageChange={controls.page}
                onPerPageChange={controls.perPage}
                label="leave requests"
            />

            {filtersOpen ? (
                <LeaveReportFiltersSheet
                    open
                    onOpenChange={setFiltersOpen}
                    filters={filters}
                    options={options}
                    onApply={controls.apply}
                    onClear={controls.clear}
                />
            ) : null}
        </Main>
    );
}
