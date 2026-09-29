import type { ProjectOption } from '../types';

export type EmployeeFilters = {
    department_id: string;
    position_id: string;
    status: string;
    manager_id: string;
    gender_id: string;
    nationality_id: string;
    visa_type_id: string;
    company_visa_type_id: string;
    rank_id: string;
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
    position_id: '',
    status: '',
    manager_id: '',
    gender_id: '',
    nationality_id: '',
    visa_type_id: '',
    company_visa_type_id: '',
    rank_id: '',
    client_id: '',
    project_id: '',
    approval_location_id: '',
    sssa_option_id: '',
    role_id: '',
    missing_fields: '',
    present_fields: '',
};

function projectHasClient(
    project: ProjectOption,
    clientId: string | number,
): boolean {
    const targetId = Number(clientId);

    if (!targetId) {
        return false;
    }

    if (Array.isArray(project.client_ids)) {
        if (project.client_ids.some((id) => Number(id) === targetId)) {
            return true;
        }
    }

    if (project.client_id != null && Number(project.client_id) === targetId) {
        return true;
    }

    return false;
}

export function filterProjectsByClient(
    projects: ProjectOption[] | undefined,
    clientId: string,
): ProjectOption[] {
    const list = projects ?? [];

    if (!clientId) {
        return list;
    }

    return list.filter((project) => projectHasClient(project, clientId));
}

export function resolveProjectOnClientChange(
    currentProjectId: string,
    nextClientId: string,
    projects: ProjectOption[] | undefined,
): string {
    if (nextClientId === '' || currentProjectId === '') {
        return currentProjectId;
    }

    const selectedProject = (projects ?? []).find(
        (project) => String(project.id) === currentProjectId,
    );

    if (!selectedProject || !projectHasClient(selectedProject, nextClientId)) {
        return '';
    }

    return currentProjectId;
}
