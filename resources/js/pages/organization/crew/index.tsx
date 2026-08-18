import { Head } from '@inertiajs/react';
import { CurrentCrewContent } from '@/features/organization/crew';
import type {
    CrewAssignmentFilterOptions,
    CrewAssignmentFilters,
    CrewAssignmentFormOptions,
    CrewAssignmentListItem,
    CrewAssignmentPagePermissions,
    CrewAssignmentSummary,
    CurrentCrewView,
    CurrentCrewVesselRow,
} from '@/features/organization/crew/types';
import type { SavedView } from '@/lib/saved-views';
import type { PaginationMeta } from '@/types/pagination';

export default function CrewAssignmentsIndex({
    view = 'crew',
    assignments,
    vessels = [],
    pagination,
    search,
    filters,
    summary,
    filter_options,
    form_options,
    can,
    saved_views = [],
}: {
    view?: CurrentCrewView;
    assignments: CrewAssignmentListItem[];
    vessels?: CurrentCrewVesselRow[];
    pagination: PaginationMeta;
    search: string;
    filters: Partial<CrewAssignmentFilters> | Record<string, unknown>;
    summary: CrewAssignmentSummary;
    filter_options: CrewAssignmentFilterOptions;
    form_options?: CrewAssignmentFormOptions;
    can: CrewAssignmentPagePermissions;
    saved_views?: SavedView[];
}) {
    return (
        <>
            <Head title="Crew Assignments" />
            <CurrentCrewContent
                view={view}
                assignments={assignments}
                vessels={vessels}
                pagination={pagination}
                search={search}
                filters={filters}
                summary={summary}
                filter_options={filter_options}
                form_options={form_options}
                can={can}
                saved_views={saved_views}
            />
        </>
    );
}
