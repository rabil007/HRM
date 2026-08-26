import { useHttp } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    GLOBAL_SEARCH_DEBOUNCE_MS,
    orderedRecordGroups,
    shouldRequestRecordSearch,
} from '@/lib/global-search';
import type {
    GlobalSearchGroup,
    GlobalSearchResponse,
} from '@/lib/global-search';
import { search } from '@/routes';

export function useGlobalSearch(): {
    query: string;
    setQuery: (value: string) => void;
    reset: () => void;
    recordGroups: GlobalSearchGroup[];
    loading: boolean;
    error: boolean;
} {
    const http = useHttp();
    const [query, setQueryState] = useState('');
    const [recordGroups, setRecordGroups] = useState<GlobalSearchGroup[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(false);
    const requestIdRef = useRef(0);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const clearPending = useCallback(() => {
        if (debounceRef.current !== null) {
            clearTimeout(debounceRef.current);
            debounceRef.current = null;
        }
    }, []);

    const reset = useCallback(() => {
        requestIdRef.current += 1;
        clearPending();
        setQueryState('');
        setRecordGroups([]);
        setLoading(false);
        setError(false);
    }, [clearPending]);

    useEffect(() => {
        return () => {
            clearPending();
        };
    }, [clearPending]);

    const setQuery = useCallback(
        (value: string) => {
            setQueryState(value);

            if (!shouldRequestRecordSearch(value)) {
                requestIdRef.current += 1;
                clearPending();
                setRecordGroups([]);
                setLoading(false);
                setError(false);

                return;
            }

            setLoading(true);
            setError(false);
            clearPending();

            debounceRef.current = setTimeout(() => {
                debounceRef.current = null;
                const requestId = requestIdRef.current + 1;
                requestIdRef.current = requestId;
                const q = value.trim();

                void http
                    .get(search.url({ query: { q } }))
                    .then((data) => {
                        if (requestId !== requestIdRef.current) {
                            return;
                        }

                        const payload = data as GlobalSearchResponse;
                        setRecordGroups(
                            orderedRecordGroups(payload.groups ?? []),
                        );
                        setLoading(false);
                        setError(false);
                    })
                    .catch(() => {
                        if (requestId !== requestIdRef.current) {
                            return;
                        }

                        setRecordGroups([]);
                        setLoading(false);
                        setError(true);
                    });
            }, GLOBAL_SEARCH_DEBOUNCE_MS);
        },
        // useHttp() returns a new object each render; keep setQuery stable.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [clearPending],
    );

    return {
        query,
        setQuery,
        reset,
        recordGroups,
        loading,
        error,
    };
}
