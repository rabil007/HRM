import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type {
    ContractLifecycleFilter,
    ContractSalaryStructureFilter,
} from '@/features/organization/contracts/types';

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

export function useContractsIndexFilters({
    url,
    initialSearch,
    initialLifecycle,
    initialStatus,
    initialPayrollCategory,
    initialSalaryStructure = '',
    initialDepartmentId = '',
    perPage = 25,
}: {
    url: string;
    initialSearch: string;
    initialLifecycle: ContractLifecycleFilter;
    initialStatus: string;
    initialPayrollCategory: string;
    initialSalaryStructure?: ContractSalaryStructureFilter;
    initialDepartmentId?: string;
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
            lifecycle:
                initialLifecycle === 'all' ? undefined : initialLifecycle,
            status: initialStatus || undefined,
            payroll_category: initialPayrollCategory || 'crew',
            salary_structure:
                initialPayrollCategory === 'crew'
                    ? initialSalaryStructure === 'monthly'
                        ? 'monthly'
                        : 'daily'
                    : undefined,
            department_id: initialDepartmentId || undefined,
            per_page: perPage,
        }),
        [
            initialDepartmentId,
            initialLifecycle,
            initialPayrollCategory,
            initialSalaryStructure,
            initialSearch,
            initialStatus,
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
                    'lifecycle',
                    'search',
                    'status',
                    'payroll_category',
                    'salary_structure',
                    'department_id',
                    'department_tree',
                    'department_tree_selected_id',
                    'contracts',
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

    const onLifecycleChange = useCallback(
        (lifecycle: ContractLifecycleFilter) => {
            visit({
                ...baseParams(),
                lifecycle: lifecycle === 'all' ? undefined : lifecycle,
                page: null,
            });
        },
        [baseParams, visit],
    );

    const onPayrollCategoryChange = useCallback(
        (payrollCategory: string) => {
            visit({
                ...baseParams(),
                payroll_category: payrollCategory,
                salary_structure:
                    payrollCategory === 'office'
                        ? undefined
                        : initialSalaryStructure === 'monthly'
                          ? 'monthly'
                          : 'daily',
                department_id: undefined,
                page: null,
            });
        },
        [baseParams, initialSalaryStructure, visit],
    );

    const onSalaryStructureChange = useCallback(
        (salaryStructure: 'daily' | 'monthly') => {
            visit({
                ...baseParams(),
                salary_structure: salaryStructure,
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
        onSearchChange,
        onLifecycleChange,
        onPayrollCategoryChange,
        onSalaryStructureChange,
        onDepartmentChange,
        onPageChange,
    };
}
