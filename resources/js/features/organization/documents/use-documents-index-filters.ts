import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ExpiryFilter } from '@/features/organization/documents/document-expiry';

function cleanParams(params: Record<string, string | number | null | undefined>): Record<string, string> {
    const clean: Record<string, string> = {};

    Object.entries(params).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            clean[key] = String(value);
        }
    });

    return clean;
}

export function useDocumentsIndexFilters({
    url,
    initialSearch,
    initialExpiry,
    perPage = 25,
}: {
    url: string;
    initialSearch: string;
    initialExpiry: ExpiryFilter;
    perPage?: number;
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

    const visit = useCallback(
        (params: Record<string, string | number | null | undefined>, only?: string[]) => {
            setIsSearching(true);
            router.get(url, cleanParams(params), {
                preserveState: true,
                replace: true,
                only: only ?? ['summary', 'expiry', 'search', 'employees', 'complianceDocuments'],
                onFinish: () => {
                    setIsSearching(false);
                    setDraftSearch(null);
                },
            });
        },
        [url],
    );

    const onSearchChange = useCallback(
        (value: string) => {
            setDraftSearch(value);

            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }

            debounceRef.current = setTimeout(() => {
                visit({
                    search: value,
                    expiry: initialExpiry === 'all' ? undefined : initialExpiry,
                    page: null,
                    per_page: initialExpiry === 'all' ? undefined : perPage,
                });
            }, 400);
        },
        [initialExpiry, perPage, visit],
    );

    const onExpiryChange = useCallback(
        (expiry: ExpiryFilter) => {
            visit({
                search: initialSearch || undefined,
                expiry: expiry === 'all' ? undefined : expiry,
                page: null,
                per_page: expiry === 'all' ? undefined : perPage,
            });
        },
        [initialSearch, perPage, visit],
    );

    const onPageChange = useCallback(
        (page: number) => {
            visit({
                search: initialSearch || undefined,
                expiry: initialExpiry === 'all' ? undefined : initialExpiry,
                page,
                per_page: perPage,
            });
        },
        [initialExpiry, initialSearch, perPage, visit],
    );

    return {
        searchInput,
        isSearching,
        onSearchChange,
        onExpiryChange,
        onPageChange,
    };
}
