import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

export function useDebouncedSearchInput(
    initialSearch: string,
    onDebouncedSearch: (value: string) => void,
    debounceMs = 400,
): {
    searchInput: string;
    onSearchChange: (value: string) => void;
} {
    const [searchInput, setSearchInput] = useState(initialSearch);
    const [submittedSearch, setSubmittedSearch] = useState(initialSearch);
    const [lastInitialSearch, setLastInitialSearch] = useState(initialSearch);
    const [isDebouncing, setIsDebouncing] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const onDebouncedSearchRef = useRef(onDebouncedSearch);

    useEffect(() => {
        onDebouncedSearchRef.current = onDebouncedSearch;
    }, [onDebouncedSearch]);

    if (initialSearch !== lastInitialSearch) {
        setLastInitialSearch(initialSearch);

        if (!isDebouncing && searchInput === submittedSearch) {
            setSearchInput(initialSearch);
            setSubmittedSearch(initialSearch);
        }
    }

    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    const onSearchChange = useCallback(
        (value: string) => {
            setSearchInput(value);
            setIsDebouncing(true);

            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }

            debounceRef.current = setTimeout(() => {
                debounceRef.current = null;
                setIsDebouncing(false);
                setSubmittedSearch(value);
                router.cancelAll();
                onDebouncedSearchRef.current(value);
            }, debounceMs);
        },
        [debounceMs],
    );

    return { searchInput, onSearchChange };
}
