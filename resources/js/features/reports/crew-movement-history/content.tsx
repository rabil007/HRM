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

const FILTER_LABELS: Partial<Record<keyof CrewMovementHistoryFilters, string>> =
    {
        status: 'Status',
        current_phase: 'Current phase',
        vessel_id: 'Vessel',
        rank_id: 'Rank',
        client_id: 'Client',
        visa_type_id: 'Sponsor / visa',
        source: 'Source',
        needs_attention: 'Needs attention',
        planned_join_from: 'Planned join from',
        planned_join_to: 'Planned join to',
        actual_join_from: 'Actual join from',
        actual_join_to: 'Actual join to',
        actual_disembarkation_from: 'Disembarkation from',
        actual_disembarkation_to: 'Disembarkation to',
        assignment_started_from: 'Assignment started from',
        assignment_started_to: 'Assignment started to',
        assignment_closed_from: 'Assignment closed from',
        assignment_closed_to: 'Assignment closed to',
        has_approved_corrections: 'Approved corrections',
        has_pending_corrections: 'Pending corrections',
    };

function humanize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function chipValueLabel(
    key: keyof CrewMovementHistoryFilters,
    value: string,
    options: CrewMovementHistoryProps['filter_options'],
): string {
    const selectOptions = (() => {
        if (key === 'status') {
            return options.statuses;
        }

        if (key === 'current_phase') {
            return options.phases;
        }

        if (key === 'source') {
            return options.sources;
        }

        if (key === 'vessel_id') {
            return options.vessels.map((option) => ({
                value: String(option.id),
                label: option.name,
            }));
        }

        if (key === 'rank_id') {
            return options.ranks.map((option) => ({
                value: String(option.id),
                label: option.name,
            }));
        }

        if (key === 'client_id') {
            return options.clients.map((option) => ({
                value: String(option.id),
                label: option.name,
            }));
        }

        if (key === 'visa_type_id') {
            return options.visa_types.map((option) => ({
                value: String(option.id),
                label: option.name,
            }));
        }

        return [];
    })();
    const selected = selectOptions.find((option) => option.value === value);

    if (selected) {
        return selected.label;
    }

    if (key.endsWith('_from') || key.endsWith('_to')) {
        return formatDisplayDate(value);
    }

    if (
        key === 'needs_attention' ||
        key === 'has_approved_corrections' ||
        key === 'has_pending_corrections'
    ) {
        return value === '1' ? 'Yes' : humanize(value);
    }

    return humanize(value);
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
                description="Scan assignment status and key dates, then open any record for its complete movement timeline and audit details."
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
                                    } as Partial<CrewMovementHistoryFilters>)
                                }
                                className="inline-flex items-center gap-1 rounded-full border bg-muted/40 px-2.5 py-1 text-xs transition-colors hover:border-primary/40 hover:bg-primary/5"
                                aria-label={`Remove ${FILTER_LABELS[key as keyof CrewMovementHistoryFilters] ?? humanize(key)} filter`}
                            >
                                {FILTER_LABELS[
                                    key as keyof CrewMovementHistoryFilters
                                ] ?? humanize(key)}
                                :{' '}
                                {chipValueLabel(
                                    key as keyof CrewMovementHistoryFilters,
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
                {assignments.length === 0 ? (
                    <EmptyState
                        title="No crew assignments found"
                        description="Try changing or clearing the report filters."
                    />
                ) : (
                    <CrewMovementHistoryReportTable
                        rows={assignments}
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
