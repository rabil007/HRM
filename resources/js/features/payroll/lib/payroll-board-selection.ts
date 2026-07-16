import type { PaginationMeta } from '@/types/pagination';

export function countRelevantExclusions(
    allBoardEmployeeIds: number[],
    excludedIds: Set<number>,
): number {
    return allBoardEmployeeIds.filter((id) => excludedIds.has(id)).length;
}

export function getPayrollBoardSelectionSummary({
    pagination,
    allBoardEmployeeIds,
    excludedIds,
    rows,
}: {
    pagination: PaginationMeta;
    allBoardEmployeeIds: number[];
    excludedIds: Set<number>;
    rows: Array<{ employee: { id: number } }>;
}) {
    const excludedCount = countRelevantExclusions(
        allBoardEmployeeIds,
        excludedIds,
    );
    const includedCount = pagination.total - excludedCount;
    const allIncluded = excludedCount === 0;
    const pageNoneSelected =
        rows.length > 0 &&
        rows.every((row) => excludedIds.has(row.employee.id));

    return {
        includedCount,
        totalCount: pagination.total,
        excludedCount,
        allIncluded,
        pageNoneSelected,
        headerChecked: allIncluded
            ? true
            : pageNoneSelected
              ? false
              : ('indeterminate' as const),
    };
}

export function pruneExcludedIds(
    excludedIds: Set<number>,
    allBoardEmployeeIds: number[],
): Set<number> {
    const validIds = new Set(allBoardEmployeeIds);
    const next = new Set([...excludedIds].filter((id) => validIds.has(id)));

    return next.size === excludedIds.size ? excludedIds : next;
}
