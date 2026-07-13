import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { TrainingExpiryFilter } from '@/features/organization/training/types';

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

export function useTrainingIndexFilters({
    url,
    initialSearch,
    initialExpiry,
    initialBranchId,
    initialDepartmentId,
    perPage = 25,
}: {
    url: string;
    initialSearch: string;
    initialExpiry: TrainingExpiryFilter;
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
            expiry: initialExpiry !== 'all' ? initialExpiry : undefined,
            branch_id: initialBranchId || undefined,
            department_id: initialDepartmentId || undefined,
            per_page: perPage,
        }),
        [
            initialSearch,
            initialExpiry,
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
                    'expiry',
                    'search',
                    'branch_id',
                    'department_id',
                    'department_tree',
                    'department_tree_selected_id',
                    'trainings',
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

    const onExpiryChange = useCallback(
        (expiry: TrainingExpiryFilter) => {
            visit({
                ...baseParams(),
                expiry: expiry !== 'all' ? expiry : undefined,
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
        onExpiryChange,
        onDepartmentChange,
        onPageChange,
    };
}
