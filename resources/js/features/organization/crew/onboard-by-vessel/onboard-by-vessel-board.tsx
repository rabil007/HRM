import { Download } from 'lucide-react';
import { useMemo } from 'react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { SelectionToolbar } from '@/components/selection/selection-toolbar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { buildOnboardVesselsExportUrl } from '@/features/organization/crew/onboard-by-vessel/build-onboard-vessels-export-url';
import { OnboardByVesselView } from '@/features/organization/crew/onboard-by-vessel/onboard-by-vessel-view';
import type { CurrentCrewVesselRow } from '@/features/organization/crew/types';
import { useRecordSelection } from '@/hooks/use-record-selection';
import type { PaginationMeta } from '@/types/pagination';

export function OnboardByVesselBoard({
    vessels,
    pagination,
    exportQuery,
    onPageChange,
    emptyTitle,
    emptyDescription,
    emptyAction,
    emptyIcon,
}: {
    vessels: CurrentCrewVesselRow[];
    pagination: PaginationMeta;
    exportQuery: Record<string, string | number | boolean | null | undefined>;
    onPageChange: (page: number) => void;
    emptyTitle: string;
    emptyDescription: string;
    emptyAction?: ReactNode;
    emptyIcon?: ReactNode;
}) {
    const visibleAssignmentIds = useMemo(
        () => vessels.flatMap((vessel) => vessel.crew.map((row) => row.id)),
        [vessels],
    );
    const selection = useRecordSelection(visibleAssignmentIds);
    const exportMode = selection.allSelectedCount > 0 ? 'selected' : 'all';
    const exportUrl = buildOnboardVesselsExportUrl(
        exportQuery,
        exportMode,
        exportMode === 'selected' ? selection.allSelectedIds : [],
    );

    if (vessels.length === 0) {
        return (
            <EmptyState
                icon={emptyIcon}
                title={emptyTitle}
                description={emptyDescription}
                action={emptyAction}
            />
        );
    }

    const toggleVisible = () => {
        if (selection.allVisibleSelected) {
            selection.deselect(visibleAssignmentIds);
        } else {
            selection.select(visibleAssignmentIds);
        }
    };

    return (
        <>
            {exportMode === 'all' ? (
                <div className="mb-4 flex justify-end">
                    <Button variant="outline" asChild>
                        <a
                            href={exportUrl}
                            title="Exports all crew matching the current filters."
                        >
                            <Download className="h-4 w-4" />
                            Export Excel
                        </a>
                    </Button>
                </div>
            ) : (
                <SelectionToolbar
                    count={selection.allSelectedCount}
                    itemLabel="crew members"
                    onClear={selection.clear}
                    selectAll={
                        <Checkbox
                            checked={selection.headerCheckboxState}
                            onCheckedChange={toggleVisible}
                            aria-label="Select all visible onboard crew"
                        />
                    }
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <a href={exportUrl}>
                                <Download className="h-4 w-4" />
                                Export Selected ({selection.allSelectedCount})
                            </a>
                        </Button>
                    }
                />
            )}

            <OnboardByVesselView
                key={vessels.map((vessel) => vessel.id).join(',')}
                vessels={vessels}
                selection={selection}
            />

            {pagination.last_page > 1 ? (
                <Pagination
                    currentPage={pagination.current_page}
                    lastPage={pagination.last_page}
                    perPage={pagination.per_page}
                    total={pagination.total}
                    from={pagination.from}
                    to={pagination.to}
                    onPageChange={onPageChange}
                />
            ) : null}
        </>
    );
}
