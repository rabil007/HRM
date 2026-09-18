import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { useDebouncedSearchInput } from '@/hooks/use-debounced-search-input';
import { index } from '@/routes/organization/reports/leave';
import type { LeaveReportFilters } from './types';

function clean(
    filters: Partial<LeaveReportFilters> & {
        page?: number;
        per_page?: number;
    },
): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== ''),
    ) as Record<string, string | number>;
}

export function useLeaveReportFilters(
    filters: LeaveReportFilters,
    perPage: number,
) {
    const [isLoading, setIsLoading] = useState(false);

    const visit = useCallback(
        (
            next: Partial<LeaveReportFilters> & {
                page?: number;
                per_page?: number;
            },
        ) => {
            setIsLoading(true);
            router.get(
                index.url(),
                clean({ ...filters, per_page: perPage, ...next }),
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    only: [
                        'leave_requests',
                        'pagination',
                        'summary',
                        'filters',
                    ],
                    onFinish: () => {
                        setIsLoading(false);
                    },
                },
            );
        },
        [filters, perPage],
    );

    const submitSearch = useCallback(
        (value: string) => {
            visit({ search: value, page: 1 });
        },
        [visit],
    );
    const {
        searchInput,
        onSearchChange: changeSearch,
        resetSearchInput,
    } = useDebouncedSearchInput(filters.search, submitSearch);

    const apply = useCallback(
        (next: Partial<LeaveReportFilters>) => visit({ ...next, page: 1 }),
        [visit],
    );

    const clear = useCallback(() => {
        router.cancelAll();
        resetSearchInput('');
        setIsLoading(true);
        router.get(
            index.url(),
            { per_page: perPage },
            {
                preserveState: true,
                replace: true,
                onFinish: () => {
                    setIsLoading(false);
                },
            },
        );
    }, [perPage, resetSearchInput]);

    const sort = useCallback(
        (column: string) =>
            visit({
                sort: column,
                direction:
                    filters.sort === column && filters.direction === 'asc'
                        ? 'desc'
                        : 'asc',
                page: 1,
            }),
        [filters.direction, filters.sort, visit],
    );

    return {
        searchInput,
        isLoading,
        changeSearch,
        apply,
        clear,
        sort,
        page: (page: number) => visit({ page }),
        perPage: (value: number) => visit({ per_page: value, page: 1 }),
    };
}
