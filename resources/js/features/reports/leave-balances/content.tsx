import { Loader2 } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import { exportMethod } from '@/routes/organization/reports/leave-balances';
import {
    LeaveBalanceReportActiveFilters,
    countSheetFilters,
} from './leave-balance-active-filters';
import { LeaveBalanceReportTable } from './report-table';
import { LeaveBalanceLeaveTypeFilterCards } from './summary-cards';
import type { LeaveBalanceReportProps } from './types';
import { useLeaveBalanceReportFilters } from './use-leave-balance-report-filters';
import { LeaveBalanceYearFilter } from './year-filter';

export function LeaveBalanceReportContent(props: LeaveBalanceReportProps) {
    const {
        balances,
        pagination,
        filters,
        filter_options: options,
        department_tree: departmentTree,
        department_tree_selected_id: departmentTreeSelectedId,
        can,
    } = props;
    const controls = useLeaveBalanceReportFilters(filters, pagination.per_page);
    const hasActiveFilters =
        countSheetFilters(filters) > 0 ||
        filters.department_id !== '' ||
        filters.leave_type_id !== '' ||
        filters.search !== '' ||
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
                title="Leave Balance Report"
                description={
                    filters.year
                        ? `Persisted entitlement ledger for ${filters.year}. This view never creates or repairs missing balances.`
                        : 'Persisted entitlement ledger. This view never creates or repairs missing balances.'
                }
                right={
                    can.export ? (
                        <ExportMenu
                            label="Export report"
                            formats={['xlsx', 'csv']}
                            getUrl={(format) =>
                                exportUrl(format === 'csv' ? 'csv' : 'xlsx')
                            }
                        />
                    ) : null
                }
            />

            <LeaveBalanceLeaveTypeFilterCards
                leaveTypes={options.leave_types}
                selectedId={filters.leave_type_id}
                onSelect={(leave_type_id) => controls.apply({ leave_type_id })}
            />

            <div className="mt-6 space-y-3">
                <SearchBar
                    placeholder="Search employee name or number..."
                    value={controls.searchInput}
                    onChange={controls.changeSearch}
                    right={
                        <div className="flex flex-wrap items-center gap-2">
                            <LeaveBalanceYearFilter
                                value={filters.year}
                                years={options.years}
                                onChange={(year) => controls.apply({ year })}
                            />
                            {departmentTree.length > 0 ? (
                                <DepartmentFilterControls
                                    department_tree={departmentTree}
                                    department_tree_selected_id={
                                        departmentTreeSelectedId
                                    }
                                    department_tree_selected_position_id={null}
                                    showPositions={false}
                                    onSelectDepartment={(id) =>
                                        controls.apply({
                                            department_id:
                                                id != null ? String(id) : '',
                                        })
                                    }
                                    buttonClassName="h-11"
                                />
                            ) : null}
                            {controls.isLoading ? (
                                <Loader2 className="size-4 animate-spin text-muted-foreground" />
                            ) : null}
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

                <LeaveBalanceReportActiveFilters
                    filters={filters}
                    searchInput={controls.searchInput}
                    options={options}
                    onClearSearch={() => controls.changeSearch('')}
                    onApply={controls.apply}
                />
            </div>

            <div className="mt-4">
                {balances.length === 0 ? (
                    <EmptyState
                        title="No leave balances found"
                        description="Try another year or clear filters. This report only lists balances that already exist."
                    />
                ) : (
                    <LeaveBalanceReportTable
                        rows={balances}
                        showActions={can.update_opening}
                        companyToday={props.company_today}
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
                label="balance rows"
            />
        </Main>
    );
}
