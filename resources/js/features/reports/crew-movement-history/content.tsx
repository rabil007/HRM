import { Download, Filter, Loader2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { exportMethod } from '@/routes/organization/reports/crew-movement-history';
import { CrewMovementHistoryFiltersSheet } from './filters-sheet';
import { CrewMovementHistoryReportTable } from './report-table';
import { CrewMovementHistorySummaryCards } from './summary-cards';
import type {
    CrewMovementHistoryFilters,
    CrewMovementHistoryProps,
} from './types';
import { useCrewMovementHistoryFilters } from './use-crew-movement-history-filters';

const CHIP_EXCLUDED = new Set(['search', 'sort', 'direction']);

function label(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

export function CrewMovementHistoryContent(props: CrewMovementHistoryProps) {
    const {
        assignments,
        pagination,
        summary,
        filters,
        filter_options: options,
        can,
    } = props;
    const [filtersOpen, setFiltersOpen] = useState(false);
    const controls = useCrewMovementHistoryFilters(
        filters,
        pagination.per_page,
    );
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
                title="Crew Movement History"
                description="One-row overview of every crew assignment, including planned and actual movement dates."
                right={
                    can.export ? (
                        <>
                            <Button variant="outline" asChild>
                                <a href={exportUrl('xlsx')}>
                                    <Download className="mr-2 size-4" />
                                    Export Excel
                                </a>
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={exportUrl('csv')}>
                                    <Download className="mr-2 size-4" />
                                    Export CSV
                                </a>
                            </Button>
                        </>
                    ) : null
                }
            />

            <CrewMovementHistorySummaryCards
                summary={summary}
                filters={filters}
                onSelect={controls.apply}
            />

            <div className="mt-6 space-y-3">
                <SearchBar
                    placeholder="Search assignment, employee, vessel or remarks..."
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
                                    Clear Filters
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
                                    } as Partial<CrewMovementHistoryFilters>)
                                }
                                className="inline-flex items-center gap-1 rounded-full border bg-muted/40 px-2.5 py-1 text-xs"
                            >
                                {label(key)}: {label(value)}
                                <X className="size-3" />
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>

            <div className="mt-4">
                {assignments.length === 0 ? (
                    <EmptyState
                        title="No crew assignments found"
                        description="Try changing or clearing the report filters."
                    />
                ) : (
                    <CrewMovementHistoryReportTable
                        rows={assignments}
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
                label="assignments"
            />

            {filtersOpen ? (
                <CrewMovementHistoryFiltersSheet
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
