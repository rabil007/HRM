import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { useDebouncedSearchInput } from '@/hooks/use-debounced-search-input';

export function useDebouncedInertiaSearch({
    url,
    initialSearch,
    only,
    debounceMs = 400,
}: {
    url: string;
    initialSearch: string;
    only: string[];
    debounceMs?: number;
}) {
    const [isSearching, setIsSearching] = useState(false);
    const submitSearch = useCallback(
        (value: string) => {
            setIsSearching(true);
            router.get(
                url,
                { search: value || undefined },
                {
                    preserveState: true,
                    replace: true,
                    only,
                    onFinish: () => {
                        setIsSearching(false);
                    },
                },
            );
        },
        [only, url],
    );
    const { searchInput, onSearchChange } = useDebouncedSearchInput(
        initialSearch,
        submitSearch,
        debounceMs,
    );

    return {
        searchInput,
        isSearching,
        onSearchChange,
    };
}
