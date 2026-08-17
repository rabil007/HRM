import { router } from '@inertiajs/react';
import { Loader2, Search, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { index as planningIndex } from '@/actions/App/Http/Controllers/Organization/CrewPlanningController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Input } from '@/components/ui/input';
import type {
    PlanningFilters,
    PlanningOption,
} from '@/features/organization/crew-planning/types';

function cleanParams(
    params: Record<string, string | number | boolean | null | undefined>,
): Record<string, string> {
    const clean: Record<string, string> = {};

    Object.entries(params).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') {
            return;
        }

        clean[key] = String(value);
    });

    return clean;
}

export function OnboardPlanningFilters({
    filters,
    vessels,
    ranks,
    perPage,
}: {
    filters: PlanningFilters;
    vessels: PlanningOption[];
    ranks: PlanningOption[];
    perPage: number;
}) {
    const [pendingSearch, setPendingSearch] = useState<string | null>(null);
    const [isSearching, setIsSearching] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const searchInput = pendingSearch ?? filters.search ?? '';

    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    const visit = useCallback(
        (
            params: Record<
                string,
                string | number | boolean | null | undefined
            >,
        ) => {
            setIsSearching(true);
            router.get(planningIndex.url(), cleanParams(params), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: [
                    'view',
                    'onboard_vessels',
                    'onboard_pagination',
                    'filters',
                    'can',
                ],
                onFinish: () => {
                    setIsSearching(false);
                    setPendingSearch(null);
                },
            });
        },
        [],
    );

    const baseParams = {
        view: 'onboard-vessels',
        search: filters.search || undefined,
        vessel_id: filters.vessel_id ?? undefined,
        rank_id: filters.rank_id ?? undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        per_page: perPage,
    };

    const onSearchChange = (value: string) => {
        setPendingSearch(value);

        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        debounceRef.current = setTimeout(() => {
            visit({
                ...baseParams,
                search: value || undefined,
                page: 1,
            });
        }, 400);
    };

    return (
        <div className="flex flex-wrap items-center gap-2 border-b bg-background/95 px-4 py-2.5">
            <AppSelect
                value={
                    filters.vessel_id !== null ? String(filters.vessel_id) : ''
                }
                onValueChange={(value) =>
                    visit({
                        ...baseParams,
                        vessel_id: value === '' ? undefined : Number(value),
                        page: 1,
                    })
                }
                placeholder="All vessels"
                searchPlaceholder="Search vessels..."
                size="sm"
                className="w-44"
            >
                <AppSelectItem value="">All vessels</AppSelectItem>
                {vessels.map((vessel) => (
                    <AppSelectItem key={vessel.id} value={String(vessel.id)}>
                        {vessel.name}
                    </AppSelectItem>
                ))}
            </AppSelect>

            <AppSelect
                value={filters.rank_id !== null ? String(filters.rank_id) : ''}
                onValueChange={(value) =>
                    visit({
                        ...baseParams,
                        rank_id: value === '' ? undefined : Number(value),
                        page: 1,
                    })
                }
                placeholder="All ranks"
                searchPlaceholder="Search ranks..."
                size="sm"
                className="w-40"
            >
                <AppSelectItem value="">All ranks</AppSelectItem>
                {ranks.map((rank) => (
                    <AppSelectItem key={rank.id} value={String(rank.id)}>
                        {rank.name}
                    </AppSelectItem>
                ))}
            </AppSelect>

            <div className="relative flex items-center">
                <Search className="absolute left-2.5 h-3.5 w-3.5 text-muted-foreground/60" />
                <Input
                    className="h-8 w-56 rounded-md pr-7 pl-8 text-sm"
                    placeholder="Search onboard crew…"
                    value={searchInput}
                    onChange={(event) => onSearchChange(event.target.value)}
                />
                {searchInput !== '' ? (
                    <button
                        type="button"
                        className="absolute right-2 text-muted-foreground transition-colors hover:text-foreground"
                        onClick={() => onSearchChange('')}
                        aria-label="Clear search"
                    >
                        <X className="h-3.5 w-3.5" />
                    </button>
                ) : null}
            </div>

            {isSearching ? (
                <Loader2
                    className="size-4 animate-spin text-muted-foreground"
                    aria-hidden
                />
            ) : null}
        </div>
    );
}
