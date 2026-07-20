import { router } from '@inertiajs/react';
import { useCallback } from 'react';
import { useDebouncedSearchInput } from '@/hooks/use-debounced-search-input';
import type { PaginationMeta } from '@/types/pagination';

export type ServerQueryParams = Record<
    string,
    string | number | boolean | null
>;

function cleanParams(params: ServerQueryParams): Record<string, string> {
    const clean: Record<string, string> = {};

    Object.entries(params).forEach(([key, value]) => {
        if (
            value !== null &&
            value !== '' &&
            value !== undefined &&
            value !== false
        ) {
            clean[key] = String(value);
        }
    });

    return clean;
}

type UseServerPaginationFiltersOptions<TFilters extends ServerQueryParams> = {
    url: string;
    search: string;
    filters: TFilters;
    pagination: PaginationMeta;
    /** Query string key for search (default: `search`). Use `q` for activity logs. */
    searchKey?: string;
    debounceMs?: number;
};

export function useServerPaginationFilters<TFilters extends ServerQueryParams>({
    url,
    search: initialSearch,
    filters: initialFilters,
    pagination,
    searchKey = 'search',
    debounceMs = 400,
}: UseServerPaginationFiltersOptions<TFilters>) {
    const baseQuery = useCallback(
        (): ServerQueryParams => ({
            ...initialFilters,
            [searchKey]: initialSearch,
        }),
        [initialFilters, initialSearch, searchKey],
    );

    const visit = useCallback(
        (overrides: ServerQueryParams = {}) => {
            router.get(
                url,
                cleanParams({
                    ...baseQuery(),
                    per_page: pagination.per_page,
                    ...overrides,
                }),
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                },
            );
        },
        [url, baseQuery, pagination.per_page],
    );

    const handleDebouncedSearch = useCallback(
        (value: string) => {
            visit({
                ...initialFilters,
                [searchKey]: value,
                page: null,
            });
        },
        [initialFilters, searchKey, visit],
    );

    const { searchInput, onSearchChange } = useDebouncedSearchInput(
        initialSearch,
        handleDebouncedSearch,
        debounceMs,
    );

    const applyFilters = useCallback(
        (next: ServerQueryParams) => {
            visit({ ...next, [searchKey]: initialSearch, page: null });
        },
        [initialSearch, searchKey, visit],
    );

    const goToPage = useCallback((page: number) => visit({ page }), [visit]);

    const setPerPage = useCallback(
        (perPage: number) => visit({ page: null, per_page: perPage }),
        [visit],
    );

    const paginationProps = {
        currentPage: pagination.current_page,
        lastPage: pagination.last_page,
        from: pagination.from,
        to: pagination.to,
        total: pagination.total,
        perPage: pagination.per_page,
        onPerPageChange: setPerPage,
        onPageChange: goToPage,
    };

    return {
        searchInput,
        onSearchChange,
        applyFilters,
        visit,
        goToPage,
        setPerPage,
        paginationProps,
    };
}
