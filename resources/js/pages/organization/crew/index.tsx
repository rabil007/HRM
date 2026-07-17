import { Head } from '@inertiajs/react';
import { CurrentCrewContent } from '@/features/organization/crew';
import type {
    CrewAssignmentFilterOptions,
    CrewAssignmentFilters,
    CrewAssignmentFormOptions,
    CrewAssignmentListItem,
    CrewAssignmentPagePermissions,
    CrewAssignmentSummary,
} from '@/features/organization/crew/types';
import type { PaginationMeta } from '@/types/pagination';

export default function CrewAssignmentsIndex({
    assignments,
    pagination,
    search,
    filters,
    summary,
    filter_options,
    form_options,
    can,
}: {
    assignments: CrewAssignmentListItem[];
    pagination: PaginationMeta;
    search: string;
    filters: Partial<CrewAssignmentFilters> | Record<string, unknown>;
    summary: CrewAssignmentSummary;
    filter_options: CrewAssignmentFilterOptions;
    form_options?: CrewAssignmentFormOptions;
    can: CrewAssignmentPagePermissions;
}) {
    return (
        <>
            <Head title="Current Crew" />
            <CurrentCrewContent
                assignments={assignments}
                pagination={pagination}
                search={search}
                filters={filters}
                summary={summary}
                filter_options={filter_options}
                form_options={form_options}
                can={can}
            />
        </>
    );
}
