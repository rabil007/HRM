import { Head } from '@inertiajs/react';
import { CurrentCrewContent } from '@/features/organization/crew';
import type {
    CrewAssignmentListItem,
    CrewAssignmentPagePermissions,
    CrewAssignmentSummary,
} from '@/features/organization/crew/types';
import type { PaginationMeta } from '@/types/pagination';

export default function CrewAssignmentsIndex({
    assignments,
    pagination,
    search,
    summary,
    can,
}: {
    assignments: CrewAssignmentListItem[];
    pagination: PaginationMeta;
    search: string;
    summary: CrewAssignmentSummary;
    filter_options: unknown;
    can: CrewAssignmentPagePermissions;
}) {
    return (
        <>
            <Head title="Current Crew" />
            <CurrentCrewContent
                assignments={assignments}
                pagination={pagination}
                search={search}
                summary={summary}
                can={can}
            />
        </>
    );
}
