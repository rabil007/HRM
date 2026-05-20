import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

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
    const [draftSearch, setDraftSearch] = useState<string | null>(null);
    const [isSearching, setIsSearching] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const searchInput = draftSearch ?? initialSearch;

    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    const onSearchChange = useCallback(
        (value: string) => {
            setDraftSearch(value);

            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }

            debounceRef.current = setTimeout(() => {
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
                            setDraftSearch(null);
                        },
                    },
                );
            }, debounceMs);
        },
        [debounceMs, only, url],
    );

    return {
        searchInput,
        isSearching,
        onSearchChange,
    };
}
