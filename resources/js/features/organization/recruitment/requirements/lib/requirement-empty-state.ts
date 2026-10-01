import type { RequirementTab } from '@/types/recruitment';

export type RequirementEmptyStateCopy = {
    title: string;
    description: string;
    showCreateAction: boolean;
    showClearAction: boolean;
};

export function resolveRequirementEmptyState({
    activeTab,
    hasSearch,
    hasActiveFilters,
    canCreate,
}: {
    activeTab: RequirementTab;
    hasSearch: boolean;
    hasActiveFilters: boolean;
    canCreate: boolean;
}): RequirementEmptyStateCopy {
    if (hasSearch || hasActiveFilters) {
        return {
            title: 'No matching requirements',
            description:
                'No requirements match your current search or filter criteria. Clear filters to see all results for this tab.',
            showCreateAction: false,
            showClearAction: true,
        };
    }

    if (activeTab === 'history') {
        return {
            title: 'No completed or cancelled requirements',
            description:
                'Completed and cancelled requests will appear here for reference.',
            showCreateAction: false,
            showClearAction: false,
        };
    }

    if (activeTab === 'on_hold') {
        return {
            title: 'No requirements on hold',
            description:
                'Paused requests appear here. Resume them when recruitment is ready to continue.',
            showCreateAction: false,
            showClearAction: false,
        };
    }

    return {
        title: 'Start your next hire here',
        description:
            'Create a staffing request with the roles and headcount you need, then assign a recruiter to keep things moving.',
        showCreateAction: canCreate,
        showClearAction: false,
    };
}
