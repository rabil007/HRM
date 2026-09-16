import type {
    CrewAssignmentFilters,
    CurrentCrewView,
} from '@/features/organization/crew/types';
import type { CrewSummaryFilter } from '@/features/organization/crew/use-crew-index-filters';

export type CrewIndexCompatibleParams = {
    search?: string;
    vessel_id?: string;
    rank_id?: string;
    client_id?: string;
    employee_id?: string;
    planned_join_from?: string;
    planned_join_to?: string;
    planned_signoff_from?: string;
    planned_signoff_to?: string;
    tour_status?: string;
    relief_status?: string;
    relief_risk?: string;
    relief_not_ready?: boolean;
    signoff_within_14_no_relief?: boolean;
    per_page?: number;
};

export function isOperationalLocationView(view: CurrentCrewView): boolean {
    return (
        view === 'pre_join_hotel' ||
        view === 'vessel' ||
        view === 'post_signoff_hotel'
    );
}

export function sanitizeFiltersForOperationalView(
    filters: CrewAssignmentFilters,
    view: CurrentCrewView,
): CrewAssignmentFilters {
    if (!isOperationalLocationView(view)) {
        return filters;
    }

    return {
        ...filters,
        phase: '',
        status: '',
        include_completed: false,
    };
}

export function resolveActiveSummaryFilter(
    filters: CrewAssignmentFilters,
    view: CurrentCrewView,
): CrewSummaryFilter {
    if (view === 'pre_join_hotel') {
        return 'pre_join_hotel';
    }

    if (view === 'vessel') {
        return 'crew_on_site';
    }

    if (view === 'post_signoff_hotel') {
        return 'post_signoff_hotel';
    }

    if (filters.movement_attention) {
        return 'attention';
    }

    return '';
}

export function queueSectionCopy(
    view: CurrentCrewView,
    filters: CrewAssignmentFilters,
): { title: string; description: string } {
    if (view === 'pre_join_hotel') {
        return {
            title: 'Pre-Join Hotel crew',
            description: 'Active pre-join crew.',
        };
    }

    if (view === 'vessel') {
        return {
            title: 'Crew On-Site',
            description: 'Vessel-grouped roster of active P4 onboard crew.',
        };
    }

    if (view === 'post_signoff_hotel') {
        return {
            title: 'Post-Sign-Off Hotel crew',
            description: 'Active crew in Demobilisation Standby.',
        };
    }

    if (filters.movement_attention) {
        return {
            title: 'Assignments needing attention',
            description:
                'Search, filter, or open a crew record for quick action.',
        };
    }

    return {
        title: 'Assignment queue',
        description: 'Search, filter, or open a crew record for quick action.',
    };
}

export function buildCrewSummaryFilterParams(
    filter: CrewSummaryFilter,
    base: CrewIndexCompatibleParams,
): Record<string, string | number | boolean | undefined> {
    const next: Record<string, string | number | boolean | undefined> = {
        ...base,
        view: undefined,
        phase: undefined,
        status: undefined,
        include_completed: undefined,
        movement_attention: undefined,
        page: 1,
    };

    if (filter === 'attention') {
        next.movement_attention = true;
    } else if (filter === 'pre_join_hotel') {
        next.view = 'pre_join_hotel';
    } else if (filter === 'crew_on_site') {
        next.view = 'vessel';
    } else if (filter === 'post_signoff_hotel') {
        next.view = 'post_signoff_hotel';
    }

    return next;
}
