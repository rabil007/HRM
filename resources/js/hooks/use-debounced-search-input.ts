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
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const submittedSearchRef = useRef(initialSearch);
    const searchInputRef = useRef(initialSearch);
    const onDebouncedSearchRef = useRef(onDebouncedSearch);

    onDebouncedSearchRef.current = onDebouncedSearch;

    useEffect(() => {
        if (debounceRef.current !== null) {
            return;
        }

        if (searchInputRef.current !== submittedSearchRef.current) {
            return;
        }

        setSearchInput(initialSearch);
        searchInputRef.current = initialSearch;
        submittedSearchRef.current = initialSearch;
    }, [initialSearch]);

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
            searchInputRef.current = value;

            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }

            debounceRef.current = setTimeout(() => {
                debounceRef.current = null;
                submittedSearchRef.current = value;
                router.cancelAll();
                onDebouncedSearchRef.current(value);
            }, debounceMs);
        },
        [debounceMs],
    );

    return { searchInput, onSearchChange };
}
