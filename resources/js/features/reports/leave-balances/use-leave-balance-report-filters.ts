import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { useDebouncedSearchInput } from '@/hooks/use-debounced-search-input';
import { index } from '@/routes/organization/reports/leave-balances';
import type { LeaveBalanceReportFilters } from './types';

function clean(
    filters: Partial<LeaveBalanceReportFilters> & {
        page?: number;
        per_page?: number;
    },
): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(([, value]) => value !== ''),
    ) as Record<string, string | number>;
}

export function useLeaveBalanceReportFilters(
    filters: LeaveBalanceReportFilters,
    perPage: number,
) {
    const [isLoading, setIsLoading] = useState(false);

    const visit = useCallback(
        (
            next: Partial<LeaveBalanceReportFilters> & {
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
                        'balances',
                        'pagination',
                        'summary',
                        'filters',
                        'department_tree_selected_id',
                    ],
                    onFinish: () => setIsLoading(false),
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
        (next: Partial<LeaveBalanceReportFilters>) =>
            visit({ ...next, page: 1 }),
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
                preserveScroll: true,
                replace: true,
                onFinish: () => setIsLoading(false),
            },
        );
    }, [perPage, resetSearchInput]);

    return {
        isLoading,
        searchInput,
        changeSearch,
        apply,
        clear,
        page: (page: number) => visit({ page }),
        perPage: (per_page: number) => visit({ per_page, page: 1 }),
    };
}
