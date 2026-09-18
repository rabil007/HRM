import {
    ChevronDown,
    Download,
    FileSpreadsheet,
    FileText,
    Filter,
    Loader2,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { formatDisplayDate } from '@/lib/format-date';
import { exportMethod } from '@/routes/organization/reports/leave';
import { LeaveReportFiltersSheet } from './filters-sheet';
import { LeaveReportTable } from './report-table';
import { LeaveReportSummaryCards } from './summary-cards';
import type { LeaveReportFilters, LeaveReportProps } from './types';
import { useLeaveReportFilters } from './use-leave-report-filters';

const CHIP_EXCLUDED = new Set(['search', 'sort', 'direction']);

const FILTER_LABELS: Partial<Record<keyof LeaveReportFilters, string>> = {
    leave_from: 'Leave from',
    leave_to: 'Leave to',
    employee_id: 'Employee',
    leave_type_id: 'Leave type',
    status: 'Status',
    department_id: 'Department',
    branch_id: 'Branch',
    submitted_from: 'Submitted from',
    submitted_to: 'Submitted to',
    decided_from: 'Decided from',
    decided_to: 'Decided to',
};

function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function chipValueLabel(
    key: keyof LeaveReportFilters,
    value: string,
    options: LeaveReportProps['filter_options'],
): string {
    if (key === 'status') {
        return (
            options.statuses.find((option) => option.value === value)?.label ??
            humanize(value)
        );
    }

    if (key === 'employee_id') {
        const employee = options.employees.find(
            (option) => String(option.id) === value,
        );

        return employee
            ? employee.employee_no
                ? `${employee.name} (${employee.employee_no})`
                : employee.name
            : humanize(value);
    }

    if (key === 'leave_type_id') {
        return (
            options.leave_types.find((option) => String(option.id) === value)
                ?.name ?? humanize(value)
        );
    }

    if (key === 'department_id') {
        return (
            options.departments.find((option) => String(option.id) === value)
                ?.name ?? humanize(value)
        );
    }

    if (key === 'branch_id') {
        return (
            options.branches.find((option) => String(option.id) === value)
                ?.name ?? humanize(value)
        );
    }

    if (key.endsWith('_from') || key.endsWith('_to')) {
        return formatDisplayDate(value);
    }

    return humanize(value);
}

export function LeaveReportContent(props: LeaveReportProps) {
    const {
        leave_requests: leaveRequests,
        pagination,
        summary,
        filters,
        filter_options: options,
        can,
    } = props;
    const [filtersOpen, setFiltersOpen] = useState(false);
    const controls = useLeaveReportFilters(filters, pagination.per_page);
    const activeChips = useMemo(
        () =>
            Object.entries(filters).filter(
                ([key, value]) => value !== '' && !CHIP_EXCLUDED.has(key),
            ),
        [filters],
    );

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
                        <div className="flex items-center gap-2">
                            {controls.isLoading ? (
                                <Loader2 className="size-4 animate-spin text-muted-foreground" />
                            ) : null}
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setFiltersOpen(true)}
                            >
                                <Filter className="mr-2 size-4" />
                                Filters
                                {activeChips.length ? (
                                    <span className="ml-2 rounded-full bg-primary/15 px-1.5 text-xs text-primary">
                                        {activeChips.length}
                                    </span>
                                ) : null}
                            </Button>
                            {activeChips.length ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={controls.clear}
                                >
                                    Clear filters
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                {activeChips.length ? (
                    <div className="flex flex-wrap gap-2">
                        {activeChips.map(([key, value]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() =>
                                    controls.apply({
                                        [key]: '',
                                    } as Partial<LeaveReportFilters>)
                                }
                                className="inline-flex items-center gap-1 rounded-full border bg-muted/40 px-2.5 py-1 text-xs transition-colors hover:border-primary/40 hover:bg-primary/5"
                                aria-label={`Remove ${FILTER_LABELS[key as keyof LeaveReportFilters] ?? humanize(key)} filter`}
                            >
                                {FILTER_LABELS[
                                    key as keyof LeaveReportFilters
                                ] ?? humanize(key)}
                                :{' '}
                                {chipValueLabel(
                                    key as keyof LeaveReportFilters,
                                    value,
                                    options,
                                )}
                                <X className="size-3" />
                            </button>
                        ))}
                    </div>
                ) : null}
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
