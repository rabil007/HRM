import type {
    ClientOption,
    PositionOption,
    ProjectOption,
    RequirementFilters,
    UserOption,
} from '@/types/recruitment';
import { clearedRequirementFilters } from './requirement-filters.ts';

export type RequirementActiveFilterChip = {
    key: string;
    label: string;
    ariaLabel: string;
    clear: Partial<RequirementFilters>;
};

export type RequirementFilterOptions = {
    clients: ClientOption[];
    projects: ProjectOption[];
    positions: PositionOption[];
    recruiters: UserOption[];
};

export function buildRequirementActiveFilterChips(
    filters: RequirementFilters,
    options: RequirementFilterOptions,
    searchInput?: string,
): RequirementActiveFilterChip[] {
    const chips: RequirementActiveFilterChip[] = [];
    const search = (searchInput ?? filters.search ?? '').trim();

    if (search !== '') {
        chips.push({
            key: 'search',
            label: `Search: ${search}`,
            ariaLabel: 'Clear search',
            clear: { search: '' },
        });
    }

    if (filters.client_id) {
        const name =
            options.clients.find(
                (item) => String(item.id) === String(filters.client_id),
            )?.name ?? String(filters.client_id);

        chips.push({
            key: 'client_id',
            label: `Client: ${name}`,
            ariaLabel: 'Clear client filter',
            clear: { client_id: null },
        });
    }

    if (filters.project_id) {
        const title =
            options.projects.find(
                (item) => String(item.id) === String(filters.project_id),
            )?.title ?? String(filters.project_id);

        chips.push({
            key: 'project_id',
            label: `Project: ${title}`,
            ariaLabel: 'Clear project filter',
            clear: { project_id: null },
        });
    }

    if (filters.position_id) {
        const title =
            options.positions.find(
                (item) => String(item.id) === String(filters.position_id),
            )?.title ?? String(filters.position_id);

        chips.push({
            key: 'position_id',
            label: `Position: ${title}`,
            ariaLabel: 'Clear position filter',
            clear: { position_id: null },
        });
    }

    if (filters.assigned_to) {
        const name =
            options.recruiters.find(
                (item) => String(item.id) === String(filters.assigned_to),
            )?.name ?? String(filters.assigned_to);

        chips.push({
            key: 'assigned_to',
            label: `Recruiter: ${name}`,
            ariaLabel: 'Clear recruiter filter',
            clear: { assigned_to: null },
        });
    }

    if (filters.priority) {
        chips.push({
            key: 'priority',
            label: `Priority: ${filters.priority === 'urgent' ? 'Urgent' : 'Normal'}`,
            ariaLabel: 'Clear priority filter',
            clear: { priority: null },
        });
    }

    if (filters.deadline_health) {
        const label =
            filters.deadline_health === 'due_soon'
                ? 'Due in 7 days'
                : filters.deadline_health === 'overdue'
                  ? 'Overdue'
                  : 'On track';

        chips.push({
            key: 'deadline_health',
            label: `Deadline: ${label}`,
            ariaLabel: 'Clear deadline filter',
            clear: { deadline_health: null },
        });
    }

    return chips;
}

export function hasRequirementActiveFilters(
    filters: RequirementFilters,
    searchInput?: string,
): boolean {
    const search = (searchInput ?? filters.search ?? '').trim();

    return Boolean(
        search ||
        filters.client_id ||
        filters.project_id ||
        filters.position_id ||
        filters.assigned_to ||
        filters.priority ||
        filters.deadline_health,
    );
}

export function clearAllRequirementFilters(): Partial<RequirementFilters> {
    return {
        ...clearedRequirementFilters,
        search: '',
    };
}
