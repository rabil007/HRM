export type EmployeeFilters = {
    department_id: string;
    department_ids: string;
    position_id: string;
    status: string;
    manager_id: string;
    gender_id: string;
    nationality_id: string;
    visa_type_id: string;
    company_visa_type_id: string;
    client_id: string;
    project_id: string;
    approval_location_id: string;
    sssa_option_id: string;
    role_id: string;
    missing_fields: string;
    present_fields: string;
};

export const EMPTY_EMPLOYEE_FILTERS: EmployeeFilters = {
    department_id: '',
    department_ids: '',
    position_id: '',
    status: '',
    manager_id: '',
    gender_id: '',
    nationality_id: '',
    visa_type_id: '',
    company_visa_type_id: '',
    client_id: '',
    project_id: '',
    approval_location_id: '',
    sssa_option_id: '',
    role_id: '',
    missing_fields: '',
    present_fields: '',
};

export function getAssignedClientIds(project: {
    client_ids?: number[] | null;
}): number[] {
    if (project.client_ids && project.client_ids.length > 0) {
        return project.client_ids.map(Number);
    }

    return [];
}

export function projectHasClient(
    project: { client_ids?: number[] | null },
    clientId: string | number | null | undefined,
): boolean {
    const targetId = Number(clientId);

    if (!targetId) {
        return false;
    }

    return getAssignedClientIds(project).includes(targetId);
}

export function filterProjectsByClient<
    T extends { client_ids?: number[] | null },
>(
    projects: T[] | undefined,
    clientId: string | number | null | undefined,
): T[] {
    const list = projects ?? [];

    if (!clientId) {
        return list;
    }

    return list.filter((project) => projectHasClient(project, clientId));
}

export function resolveProjectOnClientChange<
    T extends {
        id: number;
        client_ids?: number[] | null;
    },
>(
    currentProjectId: string | number | null | undefined,
    nextClientId: string | number | null | undefined,
    projects: T[] | undefined,
): string {
    const curIdStr = currentProjectId != null ? String(currentProjectId) : '';
    const nextIdStr = nextClientId != null ? String(nextClientId) : '';

    if (nextIdStr === '' || curIdStr === '') {
        return curIdStr;
    }

    const selectedProject = (projects ?? []).find(
        (project) => String(project.id) === curIdStr,
    );

    if (!selectedProject || !projectHasClient(selectedProject, nextIdStr)) {
        return '';
    }

    return curIdStr;
}
