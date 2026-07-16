import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

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

export function useNoBankAccountIndexFilters({
    url,
    initialSearch,
    initialPaymentMethod,
    initialDepartmentId,
    perPage = 25,
}: {
    url: string;
    initialSearch: string;
    initialPaymentMethod: string;
    initialDepartmentId: string;
    perPage?: number;
}) {
    const [pendingSearch, setPendingSearch] = useState<string | null>(null);
    const [isSearching, setIsSearching] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const searchInput = pendingSearch ?? initialSearch;

    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    const baseParams = useCallback(
        () => ({
            search: initialSearch || undefined,
            payment_method: initialPaymentMethod || undefined,
            department_id: initialDepartmentId || undefined,
            per_page: perPage,
        }),
        [initialSearch, initialPaymentMethod, initialDepartmentId, perPage],
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
                    'search',
                    'payment_method',
                    'department_id',
                    'employees',
                    'pagination',
                ],
                onFinish: () => {
                    setIsSearching(false);
                    setPendingSearch(null);
                },
            });
        },
        [url],
    );

    const onSearchChange = useCallback(
        (value: string) => {
            setPendingSearch(value);

            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }

            debounceRef.current = setTimeout(() => {
                visit({
                    ...baseParams(),
                    search: value,
                    page: null,
                });
            }, 400);
        },
        [baseParams, visit],
    );

    const onFilterChange = useCallback(
        (paymentMethod: string) => {
            visit({
                ...baseParams(),
                payment_method: paymentMethod || undefined,
                page: null,
            });
        },
        [baseParams, visit],
    );

    const onDepartmentChange = useCallback(
        (departmentId: number | null) => {
            visit({
                ...baseParams(),
                department_id:
                    departmentId !== null ? String(departmentId) : undefined,
                page: null,
            });
        },
        [baseParams, visit],
    );

    const onPageChange = useCallback(
        (page: number) => {
            visit({
                ...baseParams(),
                page,
            });
        },
        [baseParams, visit],
    );

    return {
        searchInput,
        isSearching,
        onSearchChange,
        onFilterChange,
        onDepartmentChange,
        onPageChange,
    };
}
