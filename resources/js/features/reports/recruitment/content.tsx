import {
    ChevronDown,
    Download,
    FileSpreadsheet,
    FileText,
    Loader2,
} from 'lucide-react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { exportMethod } from '@/routes/organization/recruitment/reports';
import { RecruitmentReportTable } from './report-table';
import { RecruitmentReportSummaryCards } from './summary-cards';
import type { RecruitmentReportProps } from './types';
import { useRecruitmentReportFilters } from './use-recruitment-report-filters';

export function RecruitmentReportContent(props: RecruitmentReportProps) {
    const {
        candidates,
        pagination,
        summary,
        filters,
        filter_options: options,
        can,
    } = props;

    const controls = useRecruitmentReportFilters(filters, pagination.per_page);

    const hasActiveFilters =
        filters.requirement_id !== '' ||
        filters.client_id !== '' ||
        filters.project_id !== '' ||
        filters.position_id !== '' ||
        filters.recruiter_id !== '' ||
        filters.stage !== '' ||
        filters.joining_date_from !== '' ||
        filters.joining_date_to !== '' ||
        (filters.conversion_status !== '' &&
            filters.conversion_status !== 'all') ||
        controls.searchInput.trim() !== '';

    const exportUrl = (format: 'xlsx' | 'csv'): string =>
        exportMethod.url({
            query: {
                ...buildQueryParams(filters),
                format,
            },
        });

    return (
        <Main>
            <PageHeader
                kicker="Reports"
                title="Recruitment Report"
                description="Review pipeline metrics, candidate progress, headcount fulfillment and conversion rates."
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

            <RecruitmentReportSummaryCards summary={summary} />

            <div className="mt-6 space-y-3">
                <SearchBar
                    placeholder="Search candidate name, email, phone or requirement..."
                    value={controls.searchInput}
                    onChange={controls.changeSearch}
                    right={
                        <div className="flex flex-wrap items-center gap-2">
                            {/* Stage filter */}
                            <Select
                                value={filters.stage || 'all'}
                                onValueChange={(v) =>
                                    controls.apply({
                                        stage: v === 'all' ? '' : v,
                                    })
                                }
                            >
                                <SelectTrigger className="h-11 w-44">
                                    <SelectValue placeholder="All stages" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All stages
                                    </SelectItem>
                                    {options.stages.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={s.value}
                                        >
                                            {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            {/* Conversion status filter */}
                            <Select
                                value={filters.conversion_status || 'all'}
                                onValueChange={(v) =>
                                    controls.apply({
                                        conversion_status: v === 'all' ? '' : v,
                                    })
                                }
                            >
                                <SelectTrigger className="h-11 w-52">
                                    <SelectValue placeholder="All conversion statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.conversion_statuses.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={s.value}
                                        >
                                            {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            {/* Joining date range */}
                            <DateRangeFilter
                                label="Joining date"
                                hint="Filter by expected or actual joining date."
                                from={filters.joining_date_from}
                                to={filters.joining_date_to}
                                onChange={({ from, to }) =>
                                    controls.apply({
                                        joining_date_from: from,
                                        joining_date_to: to,
                                    })
                                }
                            />

                            {/* Recruiter filter */}
                            {options.recruiters.length > 0 ? (
                                <Select
                                    value={filters.recruiter_id || 'all'}
                                    onValueChange={(v) =>
                                        controls.apply({
                                            recruiter_id: v === 'all' ? '' : v,
                                        })
                                    }
                                >
                                    <SelectTrigger className="h-11 w-44">
                                        <SelectValue placeholder="All recruiters" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All recruiters
                                        </SelectItem>
                                        {options.recruiters.map((r) => (
                                            <SelectItem
                                                key={r.value}
                                                value={r.value}
                                            >
                                                {r.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
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

                {/* Active filter pills */}
                <ActiveFilterPills
                    filters={filters}
                    options={options}
                    onApply={controls.apply}
                />
            </div>

            <div className="mt-4">
                {candidates.length === 0 ? (
                    <EmptyState
                        title="No candidates found"
                        description="Try changing or clearing the report filters."
                    />
                ) : (
                    <RecruitmentReportTable
                        rows={candidates}
                        filters={filters}
                        onSort={controls.sort}
                        canViewCandidates={can.view_candidates}
                        canViewEmployees={can.view_employees}
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
                label="candidates"
            />
        </Main>
    );
}

function buildQueryParams(
    filters: RecruitmentReportProps['filters'],
): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.requirement_id) {
        params['requirement_id'] = filters.requirement_id;
    }

    if (filters.client_id) {
        params['client_id'] = filters.client_id;
    }

    if (filters.project_id) {
        params['project_id'] = filters.project_id;
    }

    if (filters.position_id) {
        params['position_id'] = filters.position_id;
    }

    if (filters.recruiter_id) {
        params['recruiter_id'] = filters.recruiter_id;
    }

    if (filters.stage) {
        params['stage'] = filters.stage;
    }

    if (filters.joining_date_from) {
        params['joining_date_from'] = filters.joining_date_from;
    }

    if (filters.joining_date_to) {
        params['joining_date_to'] = filters.joining_date_to;
    }

    if (filters.conversion_status && filters.conversion_status !== 'all') {
        params['conversion_status'] = filters.conversion_status;
    }

    if (filters.search) {
        params['search'] = filters.search;
    }

    if (filters.sort && filters.sort !== 'created_at') {
        params['sort'] = filters.sort;
    }

    if (filters.direction && filters.direction !== 'desc') {
        params['direction'] = filters.direction;
    }

    return params;
}

function ActiveFilterPills({
    filters,
    options,
    onApply,
}: {
    filters: RecruitmentReportProps['filters'];
    options: RecruitmentReportProps['filter_options'];
    onApply: (next: Partial<RecruitmentReportProps['filters']>) => void;
}) {
    const pills: Array<{ label: string; onRemove: () => void }> = [];

    if (filters.requirement_id) {
        const req = options.requirements.find(
            (r) => r.value === filters.requirement_id,
        );
        pills.push({
            label: `Req: ${req?.label ?? filters.requirement_id}`,
            onRemove: () => onApply({ requirement_id: '' }),
        });
    }

    if (filters.client_id) {
        const client = options.clients.find(
            (c) => c.value === filters.client_id,
        );
        pills.push({
            label: `Client: ${client?.label ?? filters.client_id}`,
            onRemove: () => onApply({ client_id: '' }),
        });
    }

    if (filters.project_id) {
        const project = options.projects.find(
            (p) => p.value === filters.project_id,
        );
        pills.push({
            label: `Project: ${project?.label ?? filters.project_id}`,
            onRemove: () => onApply({ project_id: '' }),
        });
    }

    if (filters.position_id) {
        const pos = options.positions.find(
            (p) => p.value === filters.position_id,
        );
        pills.push({
            label: `Position: ${pos?.label ?? filters.position_id}`,
            onRemove: () => onApply({ position_id: '' }),
        });
    }

    if (pills.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {pills.map((pill) => (
                <button
                    key={pill.label}
                    type="button"
                    onClick={pill.onRemove}
                    className="inline-flex items-center gap-1 rounded-full border bg-muted px-2.5 py-0.5 text-xs font-medium text-foreground hover:bg-destructive/10 hover:text-destructive"
                >
                    {pill.label}
                    <span aria-hidden>×</span>
                </button>
            ))}
        </div>
    );
}
