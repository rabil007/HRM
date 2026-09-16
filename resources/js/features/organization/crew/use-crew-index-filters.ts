import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import {
    buildCrewSummaryFilterParams,
    isOperationalLocationView,
} from '@/features/organization/crew/lib/crew-summary-filter-params';
import type {
    CrewAssignmentFilters,
    CurrentCrewView,
} from '@/features/organization/crew/types';
import { useDebouncedSearchInput } from '@/hooks/use-debounced-search-input';

export type CrewSummaryFilter =
    | ''
    | 'attention'
    | 'pre_join_hotel'
    | 'crew_on_site'
    | 'post_signoff_hotel'
    | 'on_home';

function cleanParams(
    params: Record<string, string | number | boolean | null | undefined>,
): Record<string, string> {
    const clean: Record<string, string> = {};

    Object.entries(params).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '') {
            return;
        }

        if (typeof value === 'boolean') {
            if (value) {
                clean[key] = '1';
            }

            return;
        }

        clean[key] = String(value);
    });

    return clean;
}

const PARTIAL_ONLY = [
    'view',
    'assignments',
    'home_crew',
    'vessels',
    'pagination',
    'search',
    'filters',
    'summary',
    'filter_options',
    'can',
] as const;

function viewParam(view: CurrentCrewView): string | undefined {
    return view === 'crew' ? undefined : view;
}

export function useCrewIndexFilters({
    url,
    initialSearch,
    initialFilters,
    view,
    perPage = 15,
}: {
    url: string;
    initialSearch: string;
    initialFilters: CrewAssignmentFilters;
    view: CurrentCrewView;
    perPage?: number;
}) {
    const [isSearching, setIsSearching] = useState(false);

    const baseParams = useCallback(
        () => ({
            view: viewParam(view),
            search: initialSearch || undefined,
            phase: initialFilters.phase || undefined,
            status: initialFilters.status || undefined,
            vessel_id: initialFilters.vessel_id || undefined,
            rank_id: initialFilters.rank_id || undefined,
            client_id: initialFilters.client_id || undefined,
            employee_id: initialFilters.employee_id || undefined,
            planned_join_from: initialFilters.planned_join_from || undefined,
            planned_join_to: initialFilters.planned_join_to || undefined,
            planned_signoff_from:
                initialFilters.planned_signoff_from || undefined,
            planned_signoff_to: initialFilters.planned_signoff_to || undefined,
            movement_attention: initialFilters.movement_attention || undefined,
            include_completed: initialFilters.include_completed || undefined,
            tour_status: initialFilters.tour_status || undefined,
            relief_status: initialFilters.relief_status || undefined,
            relief_risk: initialFilters.relief_risk || undefined,
            relief_not_ready: initialFilters.relief_not_ready || undefined,
            signoff_within_14_no_relief:
                initialFilters.signoff_within_14_no_relief || undefined,
            per_page: perPage,
        }),
        [view, initialSearch, initialFilters, perPage],
    );

    const visit = useCallback(
        (
            params: Record<
                string,
                string | number | boolean | null | undefined
            >,
        ) => {
            setIsSearching(true);
            router.get(url, cleanParams(params), {
                preserveState: true,
                replace: true,
                only: [...PARTIAL_ONLY],
                onFinish: () => {
                    setIsSearching(false);
                },
            });
        },
        [url],
    );

    const submitSearch = useCallback(
        (value: string) => {
            visit({
                ...baseParams(),
                search: value || undefined,
                page: 1,
            });
        },
        [baseParams, visit],
    );
    const { searchInput, onSearchChange } = useDebouncedSearchInput(
        initialSearch,
        submitSearch,
        300,
    );

    const onSummaryFilterChange = useCallback(
        (filter: CrewSummaryFilter) => {
            const {
                search,
                vessel_id,
                rank_id,
                client_id,
                employee_id,
                planned_join_from,
                planned_join_to,
                planned_signoff_from,
                planned_signoff_to,
                tour_status,
                relief_status,
                relief_risk,
                relief_not_ready,
                signoff_within_14_no_relief,
                per_page,
            } = baseParams();

            visit(
                buildCrewSummaryFilterParams(filter, {
                    search,
                    vessel_id,
                    rank_id,
                    client_id,
                    employee_id,
                    planned_join_from,
                    planned_join_to,
                    planned_signoff_from,
                    planned_signoff_to,
                    tour_status,
                    relief_status,
                    relief_risk,
                    relief_not_ready,
                    signoff_within_14_no_relief,
                    per_page,
                }),
            );
        },
        [baseParams, visit],
    );

    const onSheetFiltersChange = useCallback(
        (next: CrewAssignmentFilters) => {
            const operationalLocation = isOperationalLocationView(view);

            visit({
                view: viewParam(view),
                search: initialSearch || undefined,
                phase: operationalLocation
                    ? undefined
                    : next.phase || undefined,
                status: operationalLocation
                    ? undefined
                    : next.status || undefined,
                vessel_id: next.vessel_id || undefined,
                rank_id: next.rank_id || undefined,
                client_id: next.client_id || undefined,
                employee_id: next.employee_id || undefined,
                planned_join_from: next.planned_join_from || undefined,
                planned_join_to: next.planned_join_to || undefined,
                planned_signoff_from: next.planned_signoff_from || undefined,
                planned_signoff_to: next.planned_signoff_to || undefined,
                movement_attention: next.movement_attention || undefined,
                include_completed: operationalLocation
                    ? undefined
                    : next.include_completed || undefined,
                tour_status: next.tour_status || undefined,
                relief_status: next.relief_status || undefined,
                relief_risk: next.relief_risk || undefined,
                relief_not_ready: next.relief_not_ready || undefined,
                signoff_within_14_no_relief:
                    next.signoff_within_14_no_relief || undefined,
                per_page: perPage,
                page: 1,
            });
        },
        [view, initialSearch, perPage, visit],
    );

    const onResetFilters = useCallback(() => {
        visit({
            view: viewParam(view),
            search: initialSearch || undefined,
            per_page: perPage,
            page: 1,
        });
    }, [view, initialSearch, perPage, visit]);

    const onPageChange = useCallback(
        (page: number) => {
            visit({
                ...baseParams(),
                page,
            });
        },
        [baseParams, visit],
    );

    return {
        searchInput,
        isSearching,
        onSearchChange,
        onSummaryFilterChange,
        onSheetFiltersChange,
        onResetFilters,
        onPageChange,
    };
}
