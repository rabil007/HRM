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

export function filterProjectsByClient(
    projects: ProjectOption[],
    clientId: string,
): ProjectOption[] {
    if (!clientId) {
        return projects;
    }

    return projects.filter((project) => String(project.client_id) === clientId);
}

export function resolveProjectOnClientChange(
    currentProjectId: string,
    nextClientId: string,
    projects: ProjectOption[],
): string {
    if (nextClientId === '' || currentProjectId === '') {
        return currentProjectId;
    }

    const selectedProject = projects.find(
        (project) => String(project.id) === currentProjectId,
    );

    if (
        !selectedProject ||
        String(selectedProject.client_id) !== nextClientId
    ) {
        return '';
    }

    return currentProjectId;
}
