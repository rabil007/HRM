import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { index } from '@/routes/organization/reports/crew-movement-history';
import type { CrewMovementHistoryFilters } from './types';

function clean(
    filters: Partial<CrewMovementHistoryFilters> & {
        page?: number;
        per_page?: number;
    },
): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== ''),
    ) as Record<string, string | number>;
}

export function useCrewMovementHistoryFilters(
    filters: CrewMovementHistoryFilters,
    perPage: number,
) {
    const [pendingSearch, setPendingSearch] = useState<string | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const searchInput = pendingSearch ?? filters.search;

    useEffect(
        () => () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        },
        [],
    );

    const visit = useCallback(
        (
            next: Partial<CrewMovementHistoryFilters> & {
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
                    only: ['assignments', 'pagination', 'summary', 'filters'],
                    onFinish: () => {
                        setIsLoading(false);
                        setPendingSearch(null);
                    },
                },
            );
        },
        [filters, perPage],
    );

    const changeSearch = useCallback(
        (value: string) => {
            setPendingSearch(value);

            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }

            debounceRef.current = setTimeout(
                () => visit({ search: value, page: 1 }),
                400,
            );
        },
        [visit],
    );

    const apply = useCallback(
        (next: Partial<CrewMovementHistoryFilters>) =>
            visit({ ...next, page: 1 }),
        [visit],
    );

    const clear = useCallback(() => {
        setPendingSearch('');
        setIsLoading(true);
        router.get(
            index.url(),
            { per_page: perPage },
            {
                preserveState: true,
                replace: true,
                onFinish: () => {
                    setIsLoading(false);
                    setPendingSearch(null);
                },
            },
        );
    }, [perPage]);

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
