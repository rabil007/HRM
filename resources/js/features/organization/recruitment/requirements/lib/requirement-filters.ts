import type { RequirementFilters } from '@/types/recruitment';

export const clearedRequirementFilters = {
    client_id: null,
    project_id: null,
    position_id: null,
    assigned_to: null,
    priority: null,
    deadline_health: null,
} satisfies Partial<RequirementFilters>;

export function buildRequirementQuery(
    filters: RequirementFilters,
    changes: Partial<RequirementFilters>,
    page?: number,
): Record<string, string | number> {
    const merged = { ...filters, ...changes };
    const query: Record<string, string | number> = { tab: merged.tab };

    for (const key of [
        'search',
        'client_id',
        'project_id',
        'position_id',
        'assigned_to',
        'priority',
        'deadline_health',
        'per_page',
    ] as const) {
        const value = merged[key];

        if (value !== null && value !== undefined && value !== '') {
            query[key] = value;
        }
    }

    if (page && page > 1) {
        query.page = page;
    }

    return query;
}
