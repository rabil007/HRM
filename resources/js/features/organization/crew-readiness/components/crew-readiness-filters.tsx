import { Filter, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { FiltersSheet } from '@/components/filters-sheet';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type {
    CrewReadinessFilterOptions,
    CrewReadinessFilters,
    OptionItem,
} from '@/features/organization/crew-readiness/types';

export function CrewReadinessFiltersBar({
    searchInput,
    onSearchChange,
    filters,
    vessels,
    positions,
    filterOptions,
    hasActiveQuery,
    onFilterChange,
    onReset,
}: {
    searchInput: string;
    onSearchChange: (value: string) => void;
    filters: CrewReadinessFilters;
    vessels: OptionItem[];
    positions: OptionItem[];
    filterOptions: CrewReadinessFilterOptions;
    hasActiveQuery: boolean;
    onFilterChange: (next: Partial<CrewReadinessFilters>) => void;
    onReset: () => void;
}) {
    const [sheetOpen, setSheetOpen] = useState(false);

    const availableVessels =
        filterOptions.vessels.length > 0 ? filterOptions.vessels : vessels;
    const availablePositions =
        filterOptions.positions.length > 0
            ? filterOptions.positions
            : positions;

    return (
        <>
            <SearchBar
                className="mb-4"
                value={searchInput}
                onChange={onSearchChange}
                placeholder="Search crew name, employee number, rank, or vessel..."
                right={
                    <>
                        <AppSelect
                            value={
                                filters.vessel_id != null
                                    ? String(filters.vessel_id)
                                    : ''
                            }
                            onValueChange={(value) =>
                                onFilterChange({
                                    vessel_id:
                                        value === '' ? null : Number(value),
                                })
                            }
                            variant="dark"
                            placeholder="All vessels"
                            className="hidden w-[160px] xl:inline-flex"
                        >
                            <AppSelectItem value="">All vessels</AppSelectItem>
                            {availableVessels.map((v) => (
                                <AppSelectItem key={v.id} value={String(v.id)}>
                                    {v.name ?? 'Vessel'}
                                </AppSelectItem>
                            ))}
                        </AppSelect>

                        <AppSelect
                            value={
                                filters.position_id != null
                                    ? String(filters.position_id)
                                    : ''
                            }
                            onValueChange={(value) =>
                                onFilterChange({
                                    position_id:
                                        value === '' ? null : Number(value),
                                })
                            }
                            variant="dark"
                            placeholder="All ranks"
                            className="hidden w-[160px] xl:inline-flex"
                        >
                            <AppSelectItem value="">All ranks</AppSelectItem>
                            {availablePositions.map((p) => (
                                <AppSelectItem key={p.id} value={String(p.id)}>
                                    {p.name ?? p.title ?? 'Rank'}
                                </AppSelectItem>
                            ))}
                        </AppSelect>

                        <AppSelect
                            value={filters.readiness_status}
                            onValueChange={(value) =>
                                onFilterChange({ readiness_status: value })
                            }
                            variant="dark"
                            placeholder="All Statuses"
                            className="hidden w-[145px] lg:inline-flex"
                        >
                            {(filterOptions.statuses ?? []).map((s) => (
                                <AppSelectItem key={s.value} value={s.value}>
                                    {s.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>

                        <AppSelect
                            value={filters.source}
                            onValueChange={(value) =>
                                onFilterChange({ source: value })
                            }
                            variant="dark"
                            placeholder="All Sources"
                            className="hidden w-[165px] lg:inline-flex"
                        >
                            {(filterOptions.sources ?? []).map((s) => (
                                <AppSelectItem key={s.value} value={s.value}>
                                    {s.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>

                        <AppSelect
                            value={filters.window}
                            onValueChange={(value) =>
                                onFilterChange({ window: value })
                            }
                            variant="dark"
                            placeholder="Join Window"
                            className="hidden w-[185px] sm:inline-flex"
                        >
                            {(filterOptions.windows ?? []).map((w) => (
                                <AppSelectItem key={w.value} value={w.value}>
                                    {w.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="gap-2 lg:hidden"
                            onClick={() => setSheetOpen(true)}
                        >
                            <Filter className="h-4 w-4" />
                            Filters
                        </Button>

                        {hasActiveQuery ? (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={onReset}
                                className="h-9 gap-1.5 px-2.5 text-xs text-muted-foreground hover:text-foreground"
                            >
                                <RotateCcw className="h-3.5 w-3.5" />
                                Reset
                            </Button>
                        ) : null}
                    </>
                }
            />

            <FiltersSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                onReset={onReset}
            >
                <div className="space-y-4 py-2">
                    <div className="space-y-1.5">
                        <Label>Vessel</Label>
                        <AppSelect
                            value={
                                filters.vessel_id != null
                                    ? String(filters.vessel_id)
                                    : ''
                            }
                            onValueChange={(value) =>
                                onFilterChange({
                                    vessel_id:
                                        value === '' ? null : Number(value),
                                })
                            }
                            placeholder="All vessels"
                            className="w-full"
                        >
                            <AppSelectItem value="">All vessels</AppSelectItem>
                            {availableVessels.map((v) => (
                                <AppSelectItem key={v.id} value={String(v.id)}>
                                    {v.name ?? 'Vessel'}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="space-y-1.5">
                        <Label>Rank / Position</Label>
                        <AppSelect
                            value={
                                filters.position_id != null
                                    ? String(filters.position_id)
                                    : ''
                            }
                            onValueChange={(value) =>
                                onFilterChange({
                                    position_id:
                                        value === '' ? null : Number(value),
                                })
                            }
                            placeholder="All ranks"
                            className="w-full"
                        >
                            <AppSelectItem value="">All ranks</AppSelectItem>
                            {availablePositions.map((p) => (
                                <AppSelectItem key={p.id} value={String(p.id)}>
                                    {p.name ?? p.title ?? 'Rank'}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="space-y-1.5">
                        <Label>Readiness Status</Label>
                        <AppSelect
                            value={filters.readiness_status}
                            onValueChange={(value) =>
                                onFilterChange({ readiness_status: value })
                            }
                            placeholder="All Statuses"
                            className="w-full"
                        >
                            {(filterOptions.statuses ?? []).map((s) => (
                                <AppSelectItem key={s.value} value={s.value}>
                                    {s.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="space-y-1.5">
                        <Label>Source</Label>
                        <AppSelect
                            value={filters.source}
                            onValueChange={(value) =>
                                onFilterChange({ source: value })
                            }
                            placeholder="All Sources"
                            className="w-full"
                        >
                            {(filterOptions.sources ?? []).map((s) => (
                                <AppSelectItem key={s.value} value={s.value}>
                                    {s.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>

                    <div className="space-y-1.5">
                        <Label>Join Window</Label>
                        <AppSelect
                            value={filters.window}
                            onValueChange={(value) =>
                                onFilterChange({ window: value })
                            }
                            placeholder="Join Window"
                            className="w-full"
                        >
                            {(filterOptions.windows ?? []).map((w) => (
                                <AppSelectItem key={w.value} value={w.value}>
                                    {w.label}
                                </AppSelectItem>
                            ))}
                        </AppSelect>
                    </div>
                </div>
            </FiltersSheet>
        </>
    );
}
