import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { useDebouncedSearchInput } from '@/hooks/use-debounced-search-input';
import { index } from '@/routes/organization/reports/crew-relief';
import type { CrewReliefFilters } from './types';

function clean(
    filters: Partial<CrewReliefFilters> & {
        page?: number;
        per_page?: number;
    },
): Record<string, string | number> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) =>
                value !== '' && value !== null && value !== undefined,
        ),
    ) as Record<string, string | number>;
}

export function useCrewReliefFilters(
    filters: CrewReliefFilters,
    perPage: number,
) {
    const [isLoading, setIsLoading] = useState(false);

    const visit = useCallback(
        (
            next: Partial<CrewReliefFilters> & {
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
                    only: ['rows', 'pagination', 'summary', 'filters'],
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
        (next: Partial<CrewReliefFilters>) => visit({ ...next, page: 1 }),
        [visit],
    );

    const applyPreset = useCallback(
        (preset: string) => {
            visit({
                preset,
                // When selecting a preset, clear explicit sign-off date range so the preset applies cleanly
                planned_signoff_from: '',
                planned_signoff_to: '',
                page: 1,
            });
        },
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

    return {
        searchInput,
        isLoading,
        changeSearch,
        apply,
        applyPreset,
        clear,
        visit,
    };
}
