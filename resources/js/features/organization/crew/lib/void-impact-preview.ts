import type {
    VoidAssignmentImpactItem,
    VoidBlocker,
    VoidImpactPreview,
} from '../types';

export type VoidCleanupSelections = {
    delete_sea_service: boolean;
    delete_draft_timesheet: boolean;
    delete_accommodation: boolean;
};

const CLEANUP_CODES = new Set([
    'sea_service_exists',
    'draft_timesheet_exists',
    'accommodation_history_exists',
]);

export function isCleanupEligibleBlocker(code: string): boolean {
    return CLEANUP_CODES.has(code);
}

export function collectProtectedBlockers(
    preview: VoidImpactPreview | null,
): Array<{ assignment_no: string; blockers: VoidBlocker[] }> {
    if (!preview) {
        return [];
    }

    return preview.assignments
        .map((assignment) => ({
            assignment_no: assignment.assignment_no,
            blockers:
                assignment.protected_blockers ??
                assignment.blockers.filter(
                    (blocker) => !isCleanupEligibleBlocker(blocker.code),
                ),
        }))
        .filter((item) => item.blockers.length > 0);
}

export function unresolvedCleanupRequired(
    preview: VoidImpactPreview | null,
    selections: VoidCleanupSelections,
): boolean {
    if (!preview) {
        return false;
    }

    if (preview.has_sea_service && !selections.delete_sea_service) {
        return true;
    }

    if (preview.has_draft_timesheet && !selections.delete_draft_timesheet) {
        return true;
    }

    if (preview.has_accommodation && !selections.delete_accommodation) {
        return true;
    }

    return false;
}

export function hasLinkedRecordsForCleanup(
    preview: VoidImpactPreview | null,
): boolean {
    if (!preview) {
        return false;
    }

    return (
        preview.has_sea_service ||
        preview.has_training ||
        preview.has_draft_timesheet ||
        preview.has_accommodation
    );
}

export function assignmentHasCleanupOption(
    assignment: VoidAssignmentImpactItem,
    code: string,
): boolean {
    return assignment.blockers.some((blocker) => blocker.code === code);
}

export function accommodationSummaryLabel(
    preview: VoidImpactPreview,
): string | null {
    if (!preview.has_accommodation || preview.total_accommodation_records < 1) {
        return null;
    }

    const openCount = preview.assignments.reduce(
        (sum, assignment) => sum + (assignment.accommodation?.open_count ?? 0),
        0,
    );

    const hotelNames = preview.assignments
        .flatMap((assignment) => assignment.accommodation?.summaries ?? [])
        .map((summary) => summary.hotel_name)
        .filter((name): name is string => Boolean(name));

    const uniqueHotels = [...new Set(hotelNames)];
    const parts = [
        `${preview.total_accommodation_records} record${preview.total_accommodation_records === 1 ? '' : 's'}`,
    ];

    if (uniqueHotels.length > 0) {
        parts.push(
            uniqueHotels.length <= 2
                ? uniqueHotels.join(', ')
                : `${uniqueHotels.slice(0, 2).join(', ')} +${uniqueHotels.length - 2} more`,
        );
    }

    if (openCount > 0) {
        parts.push(`${openCount} open stay${openCount === 1 ? '' : 's'}`);
    }

    return parts.join(' · ');
}
