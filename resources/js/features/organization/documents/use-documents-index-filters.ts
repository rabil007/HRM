import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ExpiryFilter } from '@/features/organization/documents/document-expiry';

function cleanParams(
    params: Record<string, string | number | null | undefined>,
): Record<string, string> {
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
    initialDepartmentId = '',
    perPage = 25,
}: {
    url: string;
    initialSearch: string;
    initialExpiry: ExpiryFilter;
    initialDepartmentId?: string;
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

    const baseParams = useCallback(
        (
            overrides: Record<string, string | number | null | undefined> = {},
        ): Record<string, string | number | null | undefined> => ({
            search: initialSearch || undefined,
            expiry: initialExpiry === 'all' ? undefined : initialExpiry,
            department_id: initialDepartmentId || undefined,
            per_page: perPage,
            page: null,
            ...overrides,
        }),
        [initialDepartmentId, initialExpiry, initialSearch, perPage],
    );

    const visit = useCallback(
        (
            params: Record<string, string | number | null | undefined>,
            only?: string[],
        ) => {
            setIsSearching(true);
            router.get(url, cleanParams(params), {
                preserveState: true,
                replace: true,
                only: only ?? [
                    'summary',
                    'expiry',
                    'search',
                    'department_id',
                    'department_tree',
                    'department_tree_selected_id',
                    'employees',
                    'searchDocuments',
                    'complianceDocuments',
                ],
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
                visit(
                    baseParams({
                        search: value || undefined,
                    }),
                );
            }, 400);
        },
        [baseParams, visit],
    );

    const onExpiryChange = useCallback(
        (expiry: ExpiryFilter) => {
            visit(
                baseParams({
                    expiry: expiry === 'all' ? undefined : expiry,
                }),
            );
        },
        [baseParams, visit],
    );

    const onDepartmentChange = useCallback(
        (departmentId: number | null) => {
            visit(
                baseParams({
                    department_id:
                        departmentId !== null
                            ? String(departmentId)
                            : undefined,
                }),
            );
        },
        [baseParams, visit],
    );

    const onPageChange = useCallback(
        (page: number) => {
            visit(
                baseParams({
                    page,
                }),
            );
        },
        [baseParams, visit],
    );

    return {
        searchInput,
        isSearching,
        onSearchChange,
        onExpiryChange,
        onDepartmentChange,
        onPageChange,
    };
}
