import type {
    ClientOption,
    ProjectOption,
} from '../../../../../types/recruitment.ts';
import {
    filterProjectsByClient,
    resolveProjectOnClientChange,
} from '../../../employees/lib/employee-client-project-filter.ts';

export function syncRequirementClientOptions(
    incoming: ClientOption[] | null | undefined,
): ClientOption[] {
    return Array.isArray(incoming) ? [...incoming] : [];
}

export function syncRequirementProjectOptions(
    incoming: ProjectOption[] | null | undefined,
): ProjectOption[] {
    return Array.isArray(incoming) ? [...incoming] : [];
}

export function appendRequirementClientOption(
    clients: ClientOption[],
    entry: { id: number | string; label: string },
): ClientOption[] {
    if (clients.some((client) => String(client.id) === String(entry.id))) {
        return clients;
    }

    return [
        ...clients,
        {
            id: Number(entry.id),
            name: entry.label,
            is_active: true,
        },
    ];
}

export function appendRequirementProjectOption(
    projects: ProjectOption[],
    entry: { id: number | string; label: string },
    clientId: string | number | null | undefined,
): ProjectOption[] {
    const selectedClientId = Number(clientId);

    if (!selectedClientId) {
        return projects;
    }

    const existingIndex = projects.findIndex(
        (project) => String(project.id) === String(entry.id),
    );

    if (existingIndex >= 0) {
        const existing = projects[existingIndex];
        const clientIds = existing.client_ids.includes(selectedClientId)
            ? existing.client_ids
            : [...existing.client_ids, selectedClientId];

        if (clientIds === existing.client_ids) {
            return projects;
        }

        const next = [...projects];
        next[existingIndex] = {
            ...existing,
            title: entry.label || existing.title,
            client_ids: clientIds,
            is_active: existing.is_active ?? true,
        };

        return next;
    }

    return [
        ...projects,
        {
            id: Number(entry.id),
            title: entry.label,
            client_ids: [selectedClientId],
            is_active: true,
        },
    ];
}

export function resolveRequirementProjectAfterClientChange(
    currentProjectId: string | number | null | undefined,
    nextClientId: string | number | null | undefined,
    projects: ProjectOption[],
): string {
    return resolveProjectOnClientChange(
        currentProjectId,
        nextClientId,
        projects,
    );
}

export function filterRequirementProjectsForClient(
    projects: ProjectOption[],
    clientId: string | number | null | undefined,
): ProjectOption[] {
    if (!clientId) {
        return [];
    }

    return filterProjectsByClient(projects, clientId);
}

export function selectableRequirementClients(
    clients: ClientOption[],
    selectedClientId: string | number | null | undefined,
): ClientOption[] {
    const activeClients = clients.filter((client) => client.is_active);

    if (!selectedClientId) {
        return activeClients;
    }

    const selected = clients.find(
        (client) => String(client.id) === String(selectedClientId),
    );

    if (
        selected &&
        !activeClients.some((client) => client.id === selected.id)
    ) {
        return [...activeClients, selected];
    }

    return activeClients;
}

export function selectableRequirementProjectsForClient(
    projects: ProjectOption[],
    clientId: string | number | null | undefined,
    selectedProjectId: string | number | null | undefined,
): ProjectOption[] {
    const linkedActive = filterRequirementProjectsForClient(
        projects.filter((project) => project.is_active),
        clientId,
    );

    if (!selectedProjectId) {
        return linkedActive;
    }

    const selected = projects.find(
        (project) => String(project.id) === String(selectedProjectId),
    );

    if (
        selected &&
        !linkedActive.some((project) => project.id === selected.id)
    ) {
        return [...linkedActive, selected];
    }

    return linkedActive;
}

export function formatRequirementProjectCreateLabel(
    query: string,
    clientName: string | null | undefined,
): string {
    const trimmed = query.trim();
    const client = (clientName ?? '').trim();

    if (client === '') {
        return `Create "${trimmed}"`;
    }

    return `Create "${trimmed}" for ${client}`;
}
