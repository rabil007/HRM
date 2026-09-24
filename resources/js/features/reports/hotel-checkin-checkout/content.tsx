import { Filter, Loader2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { EmptyState } from '@/components/empty-state';
import { ExportMenu } from '@/components/export-menu';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { exportMethod } from '@/routes/organization/reports/hotel-checkin-checkout';
import { HotelCheckInCheckoutFiltersSheet } from './filters-sheet';
import { HotelCheckInCheckoutReportTable } from './report-table';
import { HotelCheckInCheckoutSummaryCards } from './summary-cards';
import type {
    HotelCheckInCheckoutFilters,
    HotelCheckInCheckoutProps,
} from './types';
import { useHotelCheckInCheckoutFilters } from './use-hotel-checkin-checkout-filters';

const CHIP_EXCLUDED = new Set(['sort', 'direction', 'search']);

const FILTER_LABELS: Partial<
    Record<keyof HotelCheckInCheckoutFilters, string>
> = {
    hotel_id: 'Hotel',
    room_type_id: 'Room type',
    stay_type: 'Stay type',
    stay_status: 'Stay status',
    accommodation_status: 'Accommodation status',
    check_in_from: 'Check-in from',
    check_in_to: 'Check-in to',
    check_out_from: 'Check-out from',
    check_out_to: 'Check-out to',
    vessel_id: 'Vessel',
    rank_id: 'Rank',
    client_id: 'Client',
};

function chipValueLabel(
    key: keyof HotelCheckInCheckoutFilters,
    value: string,
    options: HotelCheckInCheckoutProps['filter_options'],
): string {
    if (key === 'hotel_id') {
        const match = options.hotels.find((h) => String(h.id) === value);

        return match ? match.name : value;
    }

    if (key === 'room_type_id') {
        const match = options.room_types.find((rt) => String(rt.id) === value);

        return match ? match.name : value;
    }

    if (key === 'stay_type') {
        const match = options.stay_types.find((t) => t.value === value);

        return match ? match.label : value;
    }

    if (key === 'stay_status') {
        const match = options.stay_statuses.find((s) => s.value === value);

        return match ? match.label : value;
    }

    if (key === 'accommodation_status') {
        const match = options.accommodation_statuses.find(
            (a) => a.value === value,
        );

        return match ? match.label : value;
    }

    if (key === 'vessel_id') {
        const match = options.vessels.find((v) => String(v.id) === value);

        return match ? match.name : value;
    }

    if (key === 'rank_id') {
        const match = options.ranks.find((r) => String(r.id) === value);

        return match ? match.name : value;
    }

    if (key === 'client_id') {
        const match = options.clients.find((c) => String(c.id) === value);

        return match ? match.name : value;
    }

    return value;
}

export function HotelCheckInCheckoutContent(props: HotelCheckInCheckoutProps) {
    const {
        stays,
        pagination,
        summary,
        filters,
        filter_options: options,
        can,
    } = props;
    const [sheetOpen, setSheetOpen] = useState(false);

    const controls = useHotelCheckInCheckoutFilters(
        filters,
        pagination.per_page,
    );

    const activeFilterEntries = useMemo(
        () =>
            Object.entries(filters).filter(
                ([key, value]) =>
                    !CHIP_EXCLUDED.has(key) &&
                    typeof value === 'string' &&
                    value !== '',
            ) as Array<[keyof HotelCheckInCheckoutFilters, string]>,
        [filters],
    );

    const activeFilterCount = activeFilterEntries.length;

    const exportUrl = (format: 'xlsx' | 'csv'): string => {
        const queryParams: Record<string, string> = { format };
        Object.entries(filters).forEach(([key, value]) => {
            if (value !== '') {
                queryParams[key] = value;
            }
        });

        return exportMethod.url({ query: queryParams });
    };

    const emptyDescription = useMemo(() => {
        if (filters.stay_status === 'currently_checked_in') {
            return 'No crew are currently checked in to a hotel.';
        }

        if (filters.stay_status === 'check_in_today') {
            return 'No crew are scheduled to check in today.';
        }

        if (filters.stay_status === 'checking_out_today') {
            return 'No crew are scheduled to check out today.';
        }

        return 'No hotel accommodation records match the selected filters.';
    }, [filters.stay_status]);

    return (
        <Main>
            <PageHeader
                kicker="Reports"
                title="Hotel Check-In & Check-Out"
                description="Track crew hotel check-ins, check-outs, current stays and upcoming accommodation across Crew Assignments."
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

            <HotelCheckInCheckoutSummaryCards
                summary={summary}
                filters={filters}
                onSelect={controls.apply}
            />

            <div className="mt-6 space-y-3">
                <SearchBar
                    placeholder="Search employee name, no., hotel, room, assignment, vessel..."
                    value={controls.searchInput}
                    onChange={controls.changeSearch}
                    right={
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="w-48">
                                <AppSelect
                                    value={filters.hotel_id}
                                    onValueChange={(value) =>
                                        controls.apply({
                                            hotel_id: value,
                                            room_type_id: '',
                                        })
                                    }
                                    variant="dark"
                                    placeholder="All hotels"
                                    searchPlaceholder="Search hotel..."
                                >
                                    <AppSelectItem value="">
                                        All hotels
                                    </AppSelectItem>
                                    {options.hotels.map((hotel) => (
                                        <AppSelectItem
                                            key={hotel.id}
                                            value={String(hotel.id)}
                                        >
                                            {hotel.name}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </div>

                            <div className="w-40">
                                <AppSelect
                                    value={filters.stay_type}
                                    onValueChange={(value) =>
                                        controls.apply({ stay_type: value })
                                    }
                                    variant="dark"
                                    placeholder="All stay types"
                                >
                                    <AppSelectItem value="">
                                        All stay types
                                    </AppSelectItem>
                                    {options.stay_types.map((type) => (
                                        <AppSelectItem
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </AppSelectItem>
                                    ))}
                                </AppSelect>
                            </div>

                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setSheetOpen(true)}
                                className="relative gap-2"
                            >
                                <Filter className="size-4" />
                                <span>Filters</span>
                                {activeFilterCount > 0 ? (
                                    <Badge
                                        variant="secondary"
                                        className="h-5 rounded-full px-1.5 text-xs font-semibold tabular-nums"
                                    >
                                        {activeFilterCount}
                                    </Badge>
                                ) : null}
                            </Button>

                            {activeFilterCount > 0 || filters.search !== '' ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={controls.clear}
                                    className="gap-1 text-xs text-muted-foreground hover:text-foreground"
                                >
                                    <X className="size-3.5" />
                                    <span>Reset</span>
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                {activeFilterEntries.length > 0 ? (
                    <div className="flex flex-wrap items-center gap-1.5 pt-1">
                        <span className="text-xs font-medium text-muted-foreground">
                            Active filters:
                        </span>
                        {activeFilterEntries.map(([key, value]) => (
                            <Badge
                                key={key}
                                variant="outline"
                                className="gap-1 bg-muted/40 py-1 pr-1 pl-2 text-xs font-normal"
                            >
                                <span className="font-semibold text-foreground">
                                    {FILTER_LABELS[key] ?? key}:
                                </span>
                                <span>
                                    {chipValueLabel(key, value, options)}
                                </span>
                                <button
                                    type="button"
                                    onClick={() =>
                                        controls.apply({ [key]: '' })
                                    }
                                    className="rounded p-0.5 hover:bg-muted focus-visible:outline-none"
                                    aria-label={`Remove filter ${FILTER_LABELS[key] ?? key}`}
                                >
                                    <X className="size-3 text-muted-foreground hover:text-foreground" />
                                </button>
                            </Badge>
                        ))}
                    </div>
                ) : null}

                {controls.isLoading ? (
                    <div className="flex h-32 items-center justify-center">
                        <Loader2 className="size-6 animate-spin text-muted-foreground" />
                    </div>
                ) : stays.length === 0 ? (
                    <EmptyState
                        title="No records found"
                        description={emptyDescription}
                        action={
                            activeFilterCount > 0 || filters.search !== '' ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={controls.clear}
                                >
                                    Clear filters
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <>
                        <HotelCheckInCheckoutReportTable
                            rows={stays}
                            filters={filters}
                            onSort={controls.sort}
                        />

                        <Pagination
                            currentPage={pagination.current_page}
                            lastPage={pagination.last_page}
                            perPage={pagination.per_page}
                            total={pagination.total}
                            from={pagination.from}
                            to={pagination.to}
                            onPageChange={controls.page}
                            onPerPageChange={controls.perPage}
                            perPageOptions={[25, 50, 100]}
                        />
                    </>
                )}
            </div>

            <HotelCheckInCheckoutFiltersSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                filters={filters}
                options={options}
                onApply={controls.apply}
                onClear={controls.clear}
            />
        </Main>
    );
}
