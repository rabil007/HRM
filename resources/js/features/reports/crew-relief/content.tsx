import { Filter, Loader2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { ExportMenu } from '@/components/export-menu';
import type { ExportFormat } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

const CHIP_EXCLUDED = new Set(['per_page', 'search']);

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

    if (key === 'preset') {
        const match = PRESETS.find((p) => p.key === value);

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
    preset: 'Preset',
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
                !(key === 'preset' && value === 'next_30_days'),
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

                {/* Quick Presets Strip */}
                <div className="flex flex-wrap items-center gap-1.5 border-y border-border/60 py-2.5">
                    <span className="mr-1 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        Quick Views:
                    </span>
                    {PRESETS.map((preset) => {
                        const isActive = filters.preset === preset.key;

                        return (
                            <Button
                                key={preset.key}
                                type="button"
                                variant={isActive ? 'default' : 'outline'}
                                size="sm"
                                className="h-7 text-xs font-medium"
                                onClick={() => applyPreset(preset.key)}
                            >
                                {preset.label}
                            </Button>
                        );
                    })}
                </div>

                {/* Filters Row */}
                <div className="flex flex-wrap items-center gap-2">
                    <div className="w-full sm:w-64">
                        <SearchBar
                            value={searchInput}
                            onChange={changeSearch}
                            placeholder="Search crew, vessel, rank..."
                        />
                    </div>

                    <div className="w-36">
                        <AppSelect
                            value={filters.vessel_id}
                            onValueChange={(vessel_id) => apply({ vessel_id })}
                            placeholder="All vessels"
                            searchPlaceholder="Search vessel..."
                        >
                            <AppSelectItem value="">All vessels</AppSelectItem>
                            {filterOptions.vessels.map((v) => (
                                <AppSelectItem key={v.id} value={String(v.id)}>
                                    {v.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="w-36">
                        <AppSelect
                            value={filters.client_id}
                            onValueChange={(client_id) => apply({ client_id })}
                            placeholder="All clients"
                            searchPlaceholder="Search client..."
                        >
                            <AppSelectItem value="">All clients</AppSelectItem>
                            {filterOptions.clients.map((c) => (
                                <AppSelectItem key={c.id} value={String(c.id)}>
                                    {c.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="w-36">
                        <AppSelect
                            value={filters.rank_id}
                            onValueChange={(rank_id) => apply({ rank_id })}
                            placeholder="All ranks"
                            searchPlaceholder="Search rank..."
                        >
                            <AppSelectItem value="">All ranks</AppSelectItem>
                            {filterOptions.ranks.map((r) => (
                                <AppSelectItem key={r.id} value={String(r.id)}>
                                    {r.name}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="w-36">
                        <AppSelect
                            value={filters.readiness}
                            onValueChange={(readiness) => apply({ readiness })}
                            placeholder="All readiness"
                        >
                            {filterOptions.readiness_options.map((ro) => (
                                <AppSelectItem key={ro.value} value={ro.value}>
                                    {ro.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="w-36">
                        <AppSelect
                            value={filters.attention}
                            onValueChange={(attention) => apply({ attention })}
                            placeholder="All attention"
                        >
                            {filterOptions.attention_options.map((ao) => (
                                <AppSelectItem key={ao.value} value={ao.value}>
                                    {ao.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="relative h-9 gap-1.5"
                        onClick={() => setSheetOpen(true)}
                    >
                        <Filter className="h-3.5 w-3.5" />
                        <span>Filters</span>
                        {activeFilterCount > 0 && (
                            <Badge
                                variant="secondary"
                                className="ml-1 h-4 min-w-4 rounded-full px-1 text-[10px]"
                            >
                                {activeFilterCount}
                            </Badge>
                        )}
                    </Button>

                    {activeChips.length > 0 && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-9 text-xs text-muted-foreground hover:text-foreground"
                            onClick={clear}
                        >
                            Reset filters
                        </Button>
                    )}

                    {isLoading && (
                        <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                    )}
                </div>

                {/* Active Filter Chips */}
                {activeChips.length > 0 && (
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-xs text-muted-foreground">
                            Active:
                        </span>
                        {activeChips.map(([key, value]) => (
                            <Badge
                                key={key}
                                variant="secondary"
                                className="gap-1 text-xs font-normal"
                            >
                                <span className="text-muted-foreground">
                                    {FILTER_LABELS[key] ?? key}:
                                </span>
                                <span>
                                    {chipValueLabel(
                                        key,
                                        String(value),
                                        filterOptions,
                                    )}
                                </span>
                                <button
                                    type="button"
                                    onClick={() =>
                                        key === 'preset'
                                            ? applyPreset('next_30_days')
                                            : apply({ [key]: '' })
                                    }
                                    className="ml-0.5 rounded-full p-0.5 hover:bg-muted-foreground/20"
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            </Badge>
                        ))}
                    </div>
                )}

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
