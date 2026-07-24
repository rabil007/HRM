import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { SeaServiceSheetFilters } from '@/features/organization/sea-services/components/sea-services-filters-sheet';

export type SeaServiceSummaryFilter = '' | 'active';

function cleanParams(
    params: Record<string, string | number | null | undefined>,
): Record<string, string> {
    const clean: Record<string, string> = {};

    Object.entries(params).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            clean[key] = String(value);
        }
    });

    return clean;
}

export function useSeaServicesIndexFilters({
    url,
    initialSearch,
    initialVesselId,
    initialVesselTypeId,
    initialRankId,
    initialClientId,
    initialActive,
    initialStartDate,
    initialEndDate,
    initialBranchId,
    initialDepartmentId,
    perPage = 25,
}: {
    url: string;
    initialSearch: string;
    initialVesselId: string;
    initialVesselTypeId: string;
    initialRankId: string;
    initialClientId: string;
    initialActive: string;
    initialStartDate: string;
    initialEndDate: string;
    initialBranchId: string;
    initialDepartmentId: string;
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
            vessel_id: initialVesselId || undefined,
            vessel_type_id: initialVesselTypeId || undefined,
            rank_id: initialRankId || undefined,
            client_id: initialClientId || undefined,
            active: initialActive || undefined,
            start_date: initialStartDate || undefined,
            end_date: initialEndDate || undefined,
            branch_id: initialBranchId || undefined,
            department_id: initialDepartmentId || undefined,
            per_page: perPage,
        }),
        [
            initialSearch,
            initialVesselId,
            initialVesselTypeId,
            initialRankId,
            initialClientId,
            initialActive,
            initialStartDate,
            initialEndDate,
            initialBranchId,
            initialDepartmentId,
            perPage,
        ],
    );

    const visit = useCallback(
        (params: Record<string, string | number | null | undefined>) => {
            setIsSearching(true);
            router.get(url, cleanParams(params), {
                preserveState: true,
                replace: true,
                only: [
                    'summary',
                    'search',
                    'vessel_id',
                    'vessel_type_id',
                    'rank_id',
                    'client_id',
                    'active',
                    'start_date',
                    'end_date',
                    'branch_id',
                    'department_id',
                    'department_tree',
                    'department_tree_selected_id',
                    'sea_services',
                    'pagination',
                ],
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
                    search: value,
                    page: null,
                });
            }, 400);
        },
        [baseParams, visit],
    );

    const onSummaryFilterChange = useCallback(
        (filter: SeaServiceSummaryFilter) => {
            visit({
                ...baseParams(),
                active: filter === 'active' ? '1' : undefined,
                page: null,
            });
        },
        [baseParams, visit],
    );

    const onSheetFiltersChange = useCallback(
        (next: SeaServiceSheetFilters) => {
            visit({
                ...baseParams(),
                vessel_id: next.vessel_id || undefined,
                vessel_type_id: next.vessel_type_id || undefined,
                rank_id: next.rank_id || undefined,
                client_id: next.client_id || undefined,
                start_date: next.start_date || undefined,
                end_date: next.end_date || undefined,
                page: null,
            });
        },
        [baseParams, visit],
    );

    const onDepartmentChange = useCallback(
        (departmentId: number | null) => {
            visit({
                ...baseParams(),
                department_id:
                    departmentId !== null ? String(departmentId) : undefined,
                page: null,
            });
        },
        [baseParams, visit],
    );

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
        onDepartmentChange,
        onPageChange,
    };
}
