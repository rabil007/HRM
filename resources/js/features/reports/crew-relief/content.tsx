import { Filter, Loader2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ExportMenu } from '@/components/export-menu';
import type { ExportFormat } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { exportMethod } from '@/routes/organization/reports/crew-relief';
import { CrewReliefFiltersSheet } from './filters-sheet';
import { CrewReliefReportTable } from './report-table';
import { CrewReliefSummaryCards } from './summary-cards';
import type { CrewReliefFilters, CrewReliefProps } from './types';
import { useCrewReliefFilters } from './use-crew-relief-filters';

const PRESETS = [
    { key: 'next_7_days', label: 'Next 7 Days' },
    { key: 'next_14_days', label: 'Next 14 Days' },
    { key: 'next_30_days', label: 'Next 30 Days' },
    { key: 'no_relief', label: 'No Relief' },
    { key: 'not_ready', label: 'Not Ready' },
    { key: 'overdue', label: 'Overdue' },
    { key: 'all', label: 'All Onboard' },
];

const CHIP_EXCLUDED = new Set(['per_page', 'search', 'preset']);

function chipValueLabel(
    key: keyof CrewReliefFilters,
    value: string,
    options: CrewReliefProps['filter_options'],
): string {
    if (key === 'vessel_id') {
        const match = options.vessels.find((v) => String(v.id) === value);

        return match ? match.name : value;
    }

    if (key === 'client_id') {
        const match = options.clients.find((c) => String(c.id) === value);

        return match ? match.name : value;
    }

    if (key === 'rank_id') {
        const match = options.ranks.find((r) => String(r.id) === value);

        return match ? match.name : value;
    }

    if (key === 'readiness') {
        const match = options.readiness_options.find(
            (ro) => ro.value === value,
        );

        return match ? match.label : value;
    }

    if (key === 'attention') {
        const match = options.attention_options.find(
            (ao) => ao.value === value,
        );

        return match ? match.label : value;
    }

    return value;
}

const FILTER_LABELS: Partial<Record<keyof CrewReliefFilters, string>> = {
    vessel_id: 'Vessel',
    client_id: 'Client',
    rank_id: 'Rank',
    readiness: 'Readiness',
    attention: 'Attention',
    planned_signoff_from: 'Sign-off from',
    planned_signoff_to: 'Sign-off to',
};

export function CrewReliefContent(props: CrewReliefProps) {
    const {
        rows,
        pagination,
        summary,
        filters,
        filter_options: filterOptions,
        can,
    } = props;

    const [sheetOpen, setSheetOpen] = useState(false);

    const {
        searchInput,
        isLoading,
        changeSearch,
        apply,
        applyPreset,
        clear,
        visit,
    } = useCrewReliefFilters(filters, pagination.per_page);

    const activeChips = useMemo(() => {
        return (
            Object.entries(filters) as [
                keyof CrewReliefFilters,
                string | number,
            ][]
        ).filter(
            ([key, value]) =>
                !CHIP_EXCLUDED.has(key) &&
                value !== '' &&
                value !== null &&
                value !== undefined &&
                !(key === 'readiness' && value === 'all') &&
                !(key === 'attention' && value === 'all'),
        );
    }, [filters]);

    const activeFilterCount = useMemo(() => {
        let count = 0;

        if (filters.vessel_id) {
            count++;
        }

        if (filters.client_id) {
            count++;
        }

        if (filters.rank_id) {
            count++;
        }

        if (filters.planned_signoff_from) {
            count++;
        }

        if (filters.planned_signoff_to) {
            count++;
        }

        if (filters.readiness && filters.readiness !== 'all') {
            count++;
        }

        if (filters.attention && filters.attention !== 'all') {
            count++;
        }

        return count;
    }, [filters]);

    const hasActiveFilters = useMemo(() => {
        return (
            activeChips.length > 0 ||
            Boolean(filters.search) ||
            (Boolean(filters.preset) && filters.preset !== 'next_30_days')
        );
    }, [activeChips.length, filters.search, filters.preset]);

    const exportUrl = (format: ExportFormat): string => {
        const params: Record<string, string> = { format };

        for (const [k, v] of Object.entries(filters)) {
            if (v !== '' && v !== null && v !== undefined) {
                params[k] = String(v);
            }
        }

        return exportMethod.url(params);
    };

    return (
        <Main>
            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <PageHeader
                        title="Crew Relief Report"
                        description="Track upcoming and overdue vessel sign-offs and replacement crew readiness."
                    />
                    {can.export && (
                        <div className="flex items-center gap-2">
                            <ExportMenu
                                getUrl={exportUrl}
                                formats={['xlsx', 'csv']}
                                label="Export"
                            />
                        </div>
                    )}
                </div>

                {/* Summary Cards */}
                <CrewReliefSummaryCards
                    summary={summary}
                    filters={filters}
                    onSelectPreset={applyPreset}
                />

                {/* Operational Presets */}
                <div className="flex flex-wrap items-center gap-1 rounded-xl border border-border/50 bg-muted/20 p-1">
                    {PRESETS.map((preset) => {
                        const isActive = filters.preset === preset.key;

                        return (
                            <button
                                key={preset.key}
                                type="button"
                                onClick={() => applyPreset(preset.key)}
                                className={cn(
                                    'inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium transition-all duration-150',
                                    isActive
                                        ? 'bg-background font-semibold text-foreground shadow-xs ring-1 ring-border/60'
                                        : 'text-muted-foreground hover:bg-background/40 hover:text-foreground',
                                )}
                            >
                                {preset.label}
                            </button>
                        );
                    })}
                </div>

                {/* Search & Granular Filters */}
                <div className="space-y-3">
                    <SearchBar
                        className="mb-0"
                        value={searchInput}
                        onChange={changeSearch}
                        placeholder="Search crew name, staff ID, vessel, rank, client, or remarks..."
                        right={
                            <div className="flex items-center gap-2">
                                {isLoading && (
                                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                                )}
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="h-11 rounded-xl px-4 font-medium"
                                    onClick={() => setSheetOpen(true)}
                                >
                                    <Filter className="mr-2 size-4" />
                                    Filters
                                    {activeFilterCount > 0 && (
                                        <span className="ml-2 rounded-full bg-primary/15 px-2 py-0.5 text-xs font-semibold text-primary">
                                            {activeFilterCount}
                                        </span>
                                    )}
                                </Button>
                                {hasActiveFilters && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-11 rounded-xl px-3 text-xs text-muted-foreground hover:text-foreground"
                                        onClick={clear}
                                    >
                                        Clear filters
                                    </Button>
                                )}
                            </div>
                        }
                    />

                    {/* Active Filter Chips */}
                    {activeChips.length > 0 && (
                        <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                            <span className="text-xs text-muted-foreground">
                                Active filters:
                            </span>
                            {activeChips.map(([key, value]) => (
                                <button
                                    key={key}
                                    type="button"
                                    onClick={() => apply({ [key]: '' })}
                                    className="inline-flex items-center gap-1.5 rounded-full border border-border/70 bg-muted/40 px-3 py-1 text-xs transition-colors hover:border-primary/50 hover:bg-primary/5"
                                    aria-label={`Remove ${FILTER_LABELS[key] ?? key} filter`}
                                >
                                    <span className="text-muted-foreground">
                                        {FILTER_LABELS[key] ?? key}:
                                    </span>
                                    <span className="font-medium text-foreground">
                                        {chipValueLabel(
                                            key,
                                            String(value),
                                            filterOptions,
                                        )}
                                    </span>
                                    <X className="size-3 text-muted-foreground hover:text-foreground" />
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* Table */}
                <div className="overflow-hidden rounded-xl border border-border/60 bg-card">
                    <CrewReliefReportTable rows={rows} />
                </div>

                {/* Pagination */}
                {pagination.total > 0 && (
                    <div className="flex items-center justify-between">
                        <Pagination
                            currentPage={pagination.current_page}
                            lastPage={pagination.last_page}
                            total={pagination.total}
                            from={pagination.from}
                            to={pagination.to}
                            perPage={pagination.per_page}
                            onPageChange={(page) => visit({ page })}
                            onPerPageChange={(per_page) =>
                                visit({ per_page, page: 1 })
                            }
                            perPageOptions={[25, 50, 100]}
                        />
                    </div>
                )}

                {/* Filter Sheet Modal */}
                <CrewReliefFiltersSheet
                    open={sheetOpen}
                    onOpenChange={setSheetOpen}
                    filters={filters}
                    options={filterOptions}
                    onApply={apply}
                    onReset={clear}
                />
            </div>
        </Main>
    );
}
