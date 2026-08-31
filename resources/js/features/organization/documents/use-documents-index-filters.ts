import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import type { ExpiryFilter } from '@/features/organization/documents/document-expiry';
import type { RequirementStatusFilter } from '@/features/organization/documents/shared/types';
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

export function useDocumentsIndexFilters({
    url,
    initialSearch,
    initialExpiry,
    initialRequirementStatus = '',
    initialDepartmentId = '',
    initialDocumentTypeId = '',
    perPage = 25,
}: {
    url: string;
    initialSearch: string;
    initialExpiry: ExpiryFilter;
    initialRequirementStatus?: RequirementStatusFilter;
    initialDepartmentId?: string;
    initialDocumentTypeId?: string;
    perPage?: number;
}) {
    const [isSearching, setIsSearching] = useState(false);

    const baseParams = useCallback(
        (
            overrides: Record<string, string | number | null | undefined> = {},
        ): Record<string, string | number | null | undefined> => ({
            search: initialSearch || undefined,
            expiry: initialExpiry === 'all' ? undefined : initialExpiry,
            requirement_status: initialRequirementStatus || undefined,
            department_id: initialDepartmentId || undefined,
            document_type_id: initialDocumentTypeId || undefined,
            per_page: perPage,
            page: null,
            ...overrides,
        }),
        [
            initialDepartmentId,
            initialDocumentTypeId,
            initialExpiry,
            initialRequirementStatus,
            initialSearch,
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
                    'requirement_summary',
                    'expiry',
                    'requirement_status',
                    'search',
                    'department_id',
                    'document_type_id',
                    'department_tree',
                    'department_tree_selected_id',
                    'employees',
                    'searchDocuments',
                    'complianceDocuments',
                    'requirementDocuments',
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
            visit(
                baseParams({
                    search: value || undefined,
                }),
            );
        },
        [baseParams, visit],
    );
    const { searchInput, onSearchChange } = useDebouncedSearchInput(
        initialSearch,
        submitSearch,
    );

    const onExpiryChange = useCallback(
        (expiry: ExpiryFilter) => {
            visit(
                baseParams({
                    expiry: expiry === 'all' ? undefined : expiry,
                    requirement_status: undefined,
                }),
            );
        },
        [baseParams, visit],
    );

    const onRequirementStatusChange = useCallback(
        (status: RequirementStatusFilter) => {
            visit(
                baseParams({
                    requirement_status: status || undefined,
                    expiry: undefined,
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
        onRequirementStatusChange,
        onDepartmentChange,
        onPageChange,
    };
}
