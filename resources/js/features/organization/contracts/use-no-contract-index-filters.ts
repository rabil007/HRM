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

export function useNoContractIndexFilters({
    url,
    initialSearch,
    initialPayrollCategory,
    initialDepartmentId = '',
    perPage = 25,
}: {
    url: string;
    initialSearch: string;
    initialPayrollCategory: string;
    initialDepartmentId?: string;
    perPage?: number;
}) {
    const [isSearching, setIsSearching] = useState(false);
    const activePayrollCategory =
        initialPayrollCategory === 'office' ? 'office' : 'crew';

    const baseParams = useCallback(
        () => ({
            search: initialSearch || undefined,
            payroll_category: activePayrollCategory,
            department_id: initialDepartmentId || undefined,
            per_page: perPage,
        }),
        [activePayrollCategory, initialDepartmentId, initialSearch, perPage],
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
                    'employees',
                    'pagination',
                    'search',
                    'payroll_category',
                    'department_id',
                    'department_tree',
                    'department_tree_selected_id',
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

    const onPayrollCategoryChange = useCallback(
        (payrollCategory: string) => {
            visit({
                ...baseParams(),
                payroll_category: payrollCategory,
                department_id: undefined,
                page: null,
            });
        },
        [baseParams, visit],
    );

    const onDepartmentChange = useCallback(
        (departmentId: number | null) => {
            visit({
                ...baseParams(),
                department_id: departmentId ?? undefined,
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
        activePayrollCategory,
        onSearchChange,
        onPayrollCategoryChange,
        onDepartmentChange,
        onPageChange,
    };
}
