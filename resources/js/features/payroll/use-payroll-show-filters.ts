import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { PayrollShowFilters } from '@/features/payroll/types';
import type { PaginationMeta } from '@/types/pagination';

function cleanParams(
    params: Record<string, string | number | boolean | null | undefined>,
): Record<string, string> {
    const clean: Record<string, string> = {};

    Object.entries(params).forEach(([key, value]) => {
        if (
            value !== null &&
            value !== undefined &&
            value !== '' &&
            value !== false
        ) {
            clean[key] = String(value);
        }
    });

    return clean;
}

const boardReloadProps = [
    'rows',
    'pagination',
    'all_board_employee_ids',
    'employee_stats',
    'search',
    'filters',
    'department_tree',
    'department_tree_selected_id',
    'department_tree_selected_position_id',
] as const;

const recordsReloadProps = [
    'payroll_records',
    'payroll_records_monthly',
    'payroll_records_pagination',
    'payroll_records_monthly_pagination',
    'search',
    'filters',
    'department_tree',
    'department_tree_selected_id',
    'department_tree_selected_position_id',
] as const;

export function usePayrollShowFilters({
    url,
    initialSearch,
    payrollFilters,
    pagination,
    recordsPagination,
    monthlyRecordsPagination,
    isDraft,
    supportsTimesheets,
    debounceMs = 400,
}: {
    url: string;
    initialSearch: string;
    payrollFilters: PayrollShowFilters;
    pagination: PaginationMeta;
    recordsPagination: PaginationMeta | null;
    monthlyRecordsPagination: PaginationMeta | null;
    isDraft: boolean;
    supportsTimesheets: boolean;
    debounceMs?: number;
}) {
    const [searchInput, setSearchInput] = useState(initialSearch);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        setSearchInput(initialSearch);
    }, [initialSearch]);

    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    const baseParams = useCallback(
        () => ({
            department_id: payrollFilters.department_id || undefined,
            position_id: payrollFilters.position_id || undefined,
            employee_group: payrollFilters.employee_group || undefined,
            ...(supportsTimesheets
                ? {
                      crew_salary_structure:
                          payrollFilters.crew_salary_structure,
                  }
                : {}),
            search: initialSearch || undefined,
            per_page: pagination.per_page,
        }),
        [
            initialSearch,
            pagination.per_page,
            payrollFilters.crew_salary_structure,
            payrollFilters.department_id,
            payrollFilters.employee_group,
            payrollFilters.position_id,
            supportsTimesheets,
        ],
    );

    const visit = useCallback(
        (
            params: Record<string, string | number | null | undefined>,
            only?: string[],
        ) => {
            const defaultReloadProps = isDraft
                ? [...boardReloadProps]
                : [...recordsReloadProps];

            router.get(url, cleanParams(params), {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: only ?? defaultReloadProps,
            });
        },
        [isDraft, url],
    );

    const onSearchChange = useCallback(
        (value: string) => {
            setSearchInput(value);

            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }

            debounceRef.current = setTimeout(() => {
                visit({
                    ...baseParams(),
                    search: value,
                    page: null,
                    records_page: null,
                    monthly_records_page: null,
                });
            }, debounceMs);
        },
        [baseParams, debounceMs, visit],
    );

    const applyFilters = useCallback(
        (next: Record<string, string | number | null | undefined>) => {
            visit({
                ...baseParams(),
                ...next,
                search: initialSearch || undefined,
                page: next.page ?? null,
            });
        },
        [baseParams, initialSearch, visit],
    );

    const goToPage = useCallback(
        (page: number) => {
            visit({
                ...baseParams(),
                page,
            });
        },
        [baseParams, visit],
    );

    const setPerPage = useCallback(
        (perPage: number) => {
            visit({
                ...baseParams(),
                page: null,
                per_page: perPage,
            });
        },
        [baseParams, visit],
    );

    const onCrewSalaryStructureChange = useCallback(
        (crewSalaryStructure: PayrollShowFilters['crew_salary_structure']) => {
            if (isDraft) {
                visit({
                    ...baseParams(),
                    crew_salary_structure: crewSalaryStructure,
                    page: 1,
                });

                return;
            }

            visit({
                ...baseParams(),
                crew_salary_structure: crewSalaryStructure,
                records_page:
                    crewSalaryStructure === 'daily'
                        ? 1
                        : recordsPagination?.current_page,
                monthly_records_page:
                    crewSalaryStructure === 'monthly'
                        ? 1
                        : monthlyRecordsPagination?.current_page,
            });
        },
        [
            baseParams,
            isDraft,
            monthlyRecordsPagination?.current_page,
            recordsPagination?.current_page,
            visit,
        ],
    );

    const paginationProps = {
        currentPage: pagination.current_page,
        lastPage: pagination.last_page,
        from: pagination.from,
        to: pagination.to,
        total: pagination.total,
        perPage: pagination.per_page,
        onPerPageChange: setPerPage,
        onPageChange: goToPage,
    };

    return {
        searchInput,
        onSearchChange,
        applyFilters,
        visit,
        goToPage,
        setPerPage,
        onCrewSalaryStructureChange,
        paginationProps,
    };
}
