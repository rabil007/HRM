import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { useDebouncedSearchInput } from '@/hooks/use-debounced-search-input';
import type { CrewTimelineReviewFilters } from './types';

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

const reloadProps = [
    'employees',
    'search',
    'filters',
    'department_tree',
    'department_tree_selected_id',
    'department_tree_selected_position_id',
] as const;

export function useCrewTimelineFilters({
    url,
    initialSearch,
    filters,
    debounceMs = 400,
}: {
    url: string;
    initialSearch: string;
    filters: CrewTimelineReviewFilters;
    debounceMs?: number;
}) {
    const baseParams = useCallback(
        () => ({
            search: initialSearch || undefined,
            department_id: filters.department_id || undefined,
            position_id: filters.position_id || undefined,
        }),
        [filters.department_id, filters.position_id, initialSearch],
    );

    const visit = useCallback(
        (params: Record<string, string | number | null | undefined>) => {
            router.get(url, cleanParams(params), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: [...reloadProps],
            });
        },
        [url],
    );

    const handleDebouncedSearch = useCallback(
        (value: string) => {
            visit({
                ...baseParams(),
                search: value,
            });
        },
        [baseParams, visit],
    );

    const { searchInput, onSearchChange } = useDebouncedSearchInput(
        initialSearch,
        handleDebouncedSearch,
        debounceMs,
    );

    const onDepartmentChange = useCallback(
        (departmentId: number | null) => {
            visit({
                ...baseParams(),
                department_id:
                    departmentId !== null ? String(departmentId) : undefined,
                position_id: undefined,
            });
        },
        [baseParams, visit],
    );

    const onPositionChange = useCallback(
        (positionId: number, departmentId: number) => {
            visit({
                ...baseParams(),
                department_id: String(departmentId),
                position_id: String(positionId),
            });
        },
        [baseParams, visit],
    );

    return {
        searchInput,
        onSearchChange,
        onDepartmentChange,
        onPositionChange,
    };
}
