import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { CrewAssignmentFilters } from '@/features/organization/crew/types';

export type CrewSummaryFilter =
    | ''
    | 'attention'
    | 'on_vessel'
    | 'pre_mobilisation';

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
    'assignments',
    'pagination',
    'search',
    'filters',
    'summary',
    'filter_options',
    'can',
] as const;

export function useCrewIndexFilters({
    url,
    initialSearch,
    initialFilters,
    perPage = 15,
}: {
    url: string;
    initialSearch: string;
    initialFilters: CrewAssignmentFilters;
    perPage?: number;
}) {
    const [pendingSearch, setPendingSearch] = useState<string | null>(null);
    const [isSearching, setIsSearching] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const searchInput = pendingSearch ?? initialSearch;

    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    const baseParams = useCallback(
        () => ({
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
            per_page: perPage,
        }),
        [initialSearch, initialFilters, perPage],
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
                    setPendingSearch(null);
                },
            });
        },
        [url],
    );

    const onSearchChange = useCallback(
        (value: string) => {
            setPendingSearch(value);

            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }

            debounceRef.current = setTimeout(() => {
                visit({
                    ...baseParams(),
                    search: value || undefined,
                    page: 1,
                });
            }, 300);
        },
        [baseParams, visit],
    );

    const onSummaryFilterChange = useCallback(
        (filter: CrewSummaryFilter) => {
            const next = {
                ...baseParams(),
                phase: undefined as string | undefined,
                movement_attention: undefined as boolean | undefined,
                page: 1,
            };

            if (filter === 'attention') {
                next.movement_attention = true;
            } else if (filter === 'on_vessel') {
                next.phase = 'p4';
            } else if (filter === 'pre_mobilisation') {
                next.phase = 'p0';
            }

            visit(next);
        },
        [baseParams, visit],
    );

    const onPhaseChange = useCallback(
        (phase: string) => {
            visit({
                ...baseParams(),
                phase: phase || undefined,
                movement_attention: undefined,
                page: 1,
            });
        },
        [baseParams, visit],
    );

    const onSheetFiltersChange = useCallback(
        (next: CrewAssignmentFilters) => {
            visit({
                search: initialSearch || undefined,
                phase: next.phase || undefined,
                status: next.status || undefined,
                vessel_id: next.vessel_id || undefined,
                rank_id: next.rank_id || undefined,
                client_id: next.client_id || undefined,
                employee_id: next.employee_id || undefined,
                planned_join_from: next.planned_join_from || undefined,
                planned_join_to: next.planned_join_to || undefined,
                planned_signoff_from: next.planned_signoff_from || undefined,
                planned_signoff_to: next.planned_signoff_to || undefined,
                movement_attention: next.movement_attention || undefined,
                include_completed: next.include_completed || undefined,
                per_page: perPage,
                page: 1,
            });
        },
        [initialSearch, perPage, visit],
    );

    const onResetFilters = useCallback(() => {
        visit({
            search: initialSearch || undefined,
            per_page: perPage,
            page: 1,
        });
    }, [initialSearch, perPage, visit]);

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
        onPhaseChange,
        onSheetFiltersChange,
        onResetFilters,
        onPageChange,
    };
}
