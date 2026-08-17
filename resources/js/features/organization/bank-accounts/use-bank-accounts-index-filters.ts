import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { useDebouncedSearchInput } from '@/hooks/use-debounced-search-input';

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
    initialPaymentMethod,
    initialBranchId,
    initialDepartmentId,
    perPage = 25,
}: {
    url: string;
    initialSearch: string;
    initialBankId: string;
    initialIsPrimary: string;
    initialPaymentMethod: string;
    initialBranchId: string;
    initialDepartmentId: string;
    perPage?: number;
}) {
    const [isSearching, setIsSearching] = useState(false);

    const baseParams = useCallback(
        () => ({
            search: initialSearch || undefined,
            bank_id: initialBankId || undefined,
            is_primary: initialIsPrimary || undefined,
            payment_method: initialPaymentMethod || undefined,
            branch_id: initialBranchId || undefined,
            department_id: initialDepartmentId || undefined,
            per_page: perPage,
        }),
        [
            initialSearch,
            initialBankId,
            initialIsPrimary,
            initialPaymentMethod,
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
                    'payment_method',
                    'branch_id',
                    'department_id',
                    'bank_accounts',
                    'pagination',
                ],
                onFinish: () => {
                    setIsSearching(false);
                },
            });
        },
        [url],
    );

    const submitSearch = useCallback(
        (value: string) => {
            visit({
                ...baseParams(),
                search: value,
                page: null,
            });
        },
        [baseParams, visit],
    );
    const { searchInput, onSearchChange } = useDebouncedSearchInput(
        initialSearch,
        submitSearch,
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
        (filterKey: string) => {
            if (filterKey === 'ansari') {
                visit({
                    ...baseParams(),
                    is_primary: undefined,
                    payment_method: 'cash_ansari',
                    page: null,
                });
            } else {
                visit({
                    ...baseParams(),
                    is_primary: filterKey || undefined,
                    payment_method: undefined,
                    page: null,
                });
            }
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

    return {
        searchInput,
        isSearching,
        onSearchChange,
        onBankChange,
        onIsPrimaryChange,
        onDepartmentChange,
        onPageChange,
    };
}
