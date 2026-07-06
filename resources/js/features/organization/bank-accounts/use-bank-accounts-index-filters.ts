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

export function useBankAccountsIndexFilters({
    url,
    initialSearch,
    initialBankId,
    initialIsPrimary,
    initialBranchId,
    initialDepartmentId,
    perPage = 25,
}: {
    url: string;
    initialSearch: string;
    initialBankId: string;
    initialIsPrimary: string;
    initialBranchId: string;
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
            bank_id: initialBankId || undefined,
            is_primary: initialIsPrimary || undefined,
            branch_id: initialBranchId || undefined,
            department_id: initialDepartmentId || undefined,
            per_page: perPage,
        }),
        [
            initialSearch,
            initialBankId,
            initialIsPrimary,
            initialBranchId,
            initialDepartmentId,
            perPage,
        ],
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
                    'bank_id',
                    'is_primary',
                    'branch_id',
                    'department_id',
                    'bank_accounts',
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

    const onBankChange = useCallback(
        (bankId: string) => {
            visit({
                ...baseParams(),
                bank_id: bankId || undefined,
                page: null,
            });
        },
        [baseParams, visit],
    );

    const onIsPrimaryChange = useCallback(
        (isPrimary: string) => {
            visit({
                ...baseParams(),
                is_primary: isPrimary || undefined,
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
        onBankChange,
        onIsPrimaryChange,
        onPageChange,
    };
}
